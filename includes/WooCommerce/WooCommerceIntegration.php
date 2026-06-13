<?php

declare(strict_types=1);

namespace MMP\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderService;

/**
 * WooCommerce Media Folder Integration.
 *
 * Makes the MMP folder sidebar available inside the WordPress media modal
 * whenever it is opened from a WooCommerce product edit screen — e.g. when
 * clicking "Set product image" or "Add product gallery images".
 *
 * What this class does:
 *  1. Confirms the MMP admin JS/CSS bundle is loaded on product edit screens
 *     (MediaLibraryIntegration already covers post.php, but this adds an
 *     explicit check so the dependency is self-contained).
 *  2. Injects `window.MmpConfig` and the four React portal mount-point divs
 *     into the product edit page footer — exactly as MediaLibraryIntegration
 *     does for upload.php.
 *  3. Injects a lightweight bridge script that watches for the WP media modal
 *     opening (via `wp.media` events) and repositions `#mmp-sidebar-portal`
 *     inside the modal's `.media-frame-content`, turning it into a flex row
 *     so the folder sidebar sits to the left of the media grid.
 *  4. Forwards `mmp:folder-selected` events (dispatched by the React sidebar)
 *     to the active `wp.media` backbone frame so the media grid filters by
 *     the chosen folder without a page reload.
 *
 * Requires WooCommerce to be active; silently bails if it is not.
 *
 * @package MMP\WooCommerce
 * @since   1.0.0
 */
class WooCommerceIntegration {

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderService $folderService,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Silently bails when WooCommerce is not active.
     */
    public function register(): void {
        if ( ! $this->wooActive() ) {
            return;
        }

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueAssets' ] );
        add_action( 'admin_footer',          [ $this, 'injectSidebarMount' ] );
        add_action( 'admin_footer',          [ $this, 'injectBridge' ] );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    /**
     * Ensures the compiled MMP bundle is loaded on WooCommerce product edit
     * screens. MediaLibraryIntegration covers post.php globally, but we
     * register the scripts here so this class is self-contained and the
     * correct dependency is explicit.
     *
     * @param string $hook  Current admin page hook suffix.
     */
    public function enqueueAssets( string $hook ): void {
        if ( ! $this->isProductEditScreen( $hook ) ) {
            return;
        }

        if ( ! wp_script_is( 'mmp-admin', 'enqueued' ) ) {
            wp_enqueue_style(
                'mmp-admin',
                MMP_URL . 'admin/assets/dist/mmp-admin.css',
                [],
                MMP_VERSION
            );

            wp_enqueue_script(
                'mmp-admin',
                MMP_URL . 'admin/assets/dist/mmp-admin.js',
                [],
                MMP_VERSION,
                true
            );
        }
    }

    // -------------------------------------------------------------------------
    // React mount points + MmpConfig
    // -------------------------------------------------------------------------

    /**
     * Outputs the four React portal mount-point divs and `window.MmpConfig`
     * into the footer of WooCommerce product edit/new screens.
     *
     * Mirrors MediaLibraryIntegration::injectSidebarMount() exactly so the
     * same React application boots here as on upload.php.
     */
    public function injectSidebarMount(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! in_array( $screen->base, [ 'post', 'post-new' ], true )
            || 'product' !== $screen->post_type
        ) {
            return;
        }

        global $wpdb;

        $userId     = get_current_user_id();
        $settings   = (array) get_option( 'mmp_settings', [] );
        $folderMode = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        $treeUserId = ( 'per_user' === $folderMode ) ? $userId : 0;

        // Always load a fresh tree (flush stale transients first).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mmp_tree_%'
                OR option_name LIKE '_transient_timeout_mmp_tree_%'"
        );

        $initialTree = $this->folderService->getTree( $treeUserId );
        $userPrefs   = $this->getUserPrefs( $userId );

        $config = [
            'restUrl'     => rest_url( 'mmp/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'initialTree' => $initialTree,
            'userPrefs'   => $userPrefs,
            'licenceTier' => 'pro',
        ];

        echo '<div id="mmp-root" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-sidebar-portal" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-breadcrumb-root" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-toolbar-root" style="display:none;"></div>' . "\n";
        echo '<script>window.MmpConfig = ' . wp_json_encode( $config ) . ';</script>' . "\n";
        echo '<style>
#mmp-sidebar-portal { height:100%; overflow:hidden; flex-shrink:0; }
#mmp-media-content-inner { flex:1; min-width:0; overflow:hidden; display:flex; flex-direction:column; }
#mmp-media-content-inner .wp-filter { flex-shrink:0; }
#mmp-media-content-inner .attachments-browser { flex:1; min-height:0; overflow-y:auto; }
</style>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Bridge JS
    // -------------------------------------------------------------------------

    /**
     * Injects a JavaScript bridge that:
     *
     *  1. Hooks into the `wp.media` backbone events so it knows when a media
     *     modal frame is opened from the product edit screen.
     *  2. Repositions `#mmp-sidebar-portal` inside the modal's
     *     `.media-frame-content`, wrapping the existing WP children in a
     *     `#mmp-media-content-inner` flex column — the same layout used on
     *     upload.php.
     *  3. Resets the sidebar placement every time the modal is closed so it
     *     re-attaches cleanly the next time the modal opens.
     *  4. Listens for `mmp:folder-selected` custom events (dispatched by the
     *     React FolderSidebar) and forwards them to the active `wp.media`
     *     frame's attachment collection — identical to the upload.php bridge.
     */
    public function injectBridge(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! in_array( $screen->base, [ 'post', 'post-new' ], true )
            || 'product' !== $screen->post_type
        ) {
            return;
        }
        ?>
<script>
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. Wait for wp.media to be available, then hook into frame lifecycle
    // -------------------------------------------------------------------------

    function bootWhenReady() {
        if ( ! window.wp || ! window.wp.media ) {
            setTimeout( bootWhenReady, 200 );
            return;
        }
        hookMediaFrames();
    }

    // -------------------------------------------------------------------------
    // 2. Hook every new wp.media frame that opens on this page
    // -------------------------------------------------------------------------

    function hookMediaFrames() {
        var OriginalFrame = wp.media.view.MediaFrame.Select;

        // Extend the base Select frame so every WC media button inherits the hook.
        wp.media.view.MediaFrame.Select = OriginalFrame.extend({
            initialize: function () {
                OriginalFrame.prototype.initialize.apply( this, arguments );

                var self = this;

                // When the modal finishes rendering, place the sidebar.
                this.on( 'open',   function () { setTimeout( positionSidebar, 100 ); } );
                this.on( 'open',   setTimeout.bind( null, positionSidebar, 300 ) );

                // When the modal closes, reset placement so it re-attaches next time.
                this.on( 'close',  resetSidebarPlacement );
            },
        });
    }

    // -------------------------------------------------------------------------
    // 3. Position #mmp-sidebar-portal inside the open modal frame
    // -------------------------------------------------------------------------

    function positionSidebar() {
        var sidebarPortal = document.getElementById( 'mmp-sidebar-portal' );
        if ( ! sidebarPortal ) return;
        if ( sidebarPortal.getAttribute( 'data-mmp-placed' ) === '1' ) return;

        // The modal may render inside .media-modal or at the top of .media-frame-content.
        var frame = document.querySelector( '.media-modal .media-frame-content' )
                 || document.querySelector( '.media-frame-content' );
        if ( ! frame ) return;

        // Bail if WP backbone hasn't rendered the grid yet.
        if ( ! frame.querySelector( '.wp-filter' ) && ! frame.querySelector( '.attachments-browser' ) ) {
            setTimeout( positionSidebar, 200 );
            return;
        }

        // Turn the frame into a horizontal flex container.
        frame.style.cssText += ';display:flex!important;flex-direction:row;overflow:hidden;';

        // Insert sidebar as first child.
        frame.insertBefore( sidebarPortal, frame.firstChild );
        sidebarPortal.style.cssText = 'flex-shrink:0;height:100%;overflow:hidden;position:relative;display:block;';

        // Wrap WP's children (.wp-filter + .attachments-browser) in a flex column.
        if ( ! document.getElementById( 'mmp-media-content-inner' ) ) {
            var inner = document.createElement( 'div' );
            inner.id  = 'mmp-media-content-inner';
            inner.style.cssText = 'flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;';

            Array.prototype.slice.call( frame.children ).forEach( function ( child ) {
                if ( child !== sidebarPortal ) {
                    inner.appendChild( child );
                }
            } );
            frame.appendChild( inner );
        }

        sidebarPortal.setAttribute( 'data-mmp-placed', '1' );
        attachMutationObserver();
    }

    // -------------------------------------------------------------------------
    // 4. Reset placement when the modal closes so it re-attaches next open
    // -------------------------------------------------------------------------

    function resetSidebarPlacement() {
        var sidebarPortal = document.getElementById( 'mmp-sidebar-portal' );
        if ( sidebarPortal ) {
            sidebarPortal.removeAttribute( 'data-mmp-placed' );
            sidebarPortal.style.cssText = 'display:none;';
            // Move portal back to body so it's not lost when the modal DOM is removed.
            document.body.appendChild( sidebarPortal );
        }

        var inner = document.getElementById( 'mmp-media-content-inner' );
        if ( inner && inner.parentNode ) {
            // Un-wrap children back into the frame.
            var parent = inner.parentNode;
            Array.prototype.slice.call( inner.children ).forEach( function ( child ) {
                parent.insertBefore( child, inner );
            } );
            parent.removeChild( inner );
        }
    }

    // -------------------------------------------------------------------------
    // 5. Selection observer — keep React selectionStore in sync
    // -------------------------------------------------------------------------

    var _observer = null;

    function attachMutationObserver() {
        if ( _observer ) return;

        var grid = document.querySelector( '.attachments' );
        if ( ! grid ) return;

        _observer = new MutationObserver( function ( mutations ) {
            var relevant = mutations.some( function ( m ) {
                return m.type === 'attributes' && m.attributeName === 'class';
            } );
            if ( relevant ) {
                var ids = [];
                document.querySelectorAll( '.attachment.selected' ).forEach( function ( el ) {
                    var id = parseInt( el.getAttribute( 'data-id' ) || '0', 10 );
                    if ( id > 0 ) ids.push( id );
                } );
                window.dispatchEvent( new CustomEvent( 'mmp:selection-change', { detail: { ids: ids } } ) );
            }
        } );

        _observer.observe( grid, { subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
    }

    // -------------------------------------------------------------------------
    // 6. Forward mmp:folder-selected → wp.media backbone frame
    // -------------------------------------------------------------------------

    window.addEventListener( 'mmp:folder-selected', function ( e ) {
        if ( ! window.wp || ! window.wp.media ) return;

        var frame = wp.media.frame;
        if ( ! frame ) return;

        var state   = frame.state && frame.state();
        var library = state && state.get && state.get( 'library' );
        if ( ! library || ! library.props ) return;

        var folderId = e.detail && e.detail.folderId !== undefined ? e.detail.folderId : null;

        if ( folderId === null ) {
            library.props.unset( 'mmp_folder_id' );
        } else {
            library.props.set( { mmp_folder_id: String( folderId ) } );
        }
        library.props.trigger( 'change' );
    } );

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootWhenReady );
    } else {
        bootWhenReady();
    }
}());
</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param string $hook  Admin page hook suffix from admin_enqueue_scripts.
     */
    private function isProductEditScreen( string $hook ): bool {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && 'product' === $screen->post_type;
    }

    /**
     * Retrieves saved user preferences, falling back to defaults.
     *
     * @param  int $userId
     * @return array<string, mixed>
     */
    private function getUserPrefs( int $userId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w
                 FROM {$wpdb->prefix}mmp_user_prefs
                 WHERE user_id = %d
                 LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            return [ 'folder_id' => null, 'sort_files' => 'date', 'sort_dir' => 'desc', 'sidebar_w' => 300 ];
        }

        return [
            'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
            'sort_files' => (string) ( $row['sort_files'] ?? 'date' ),
            'sort_dir'   => (string) ( $row['sort_dir']   ?? 'desc' ),
            'sidebar_w'  => (int)    ( $row['sidebar_w']  ?? 300 ),
        ];
    }

    /**
     * Returns true when WooCommerce is active.
     */
    private function wooActive(): bool {
        return function_exists( 'WC' ) || class_exists( 'WooCommerce' );
    }
}
