<?php

declare(strict_types=1);

namespace MMP\PostType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;
use MMP\Folder\FolderService;
use MMP\Taxonomy\FolderTaxonomy;

/**
 * Integrates the MMP folder system with custom post type list screens.
 *
 * For each CPT enabled on the Settings page this class:
 *  1. Extends the `mmp_folder` taxonomy to cover that post type.
 *  2. Adds a "Folder" column to the WP list table.
 *  3. Filters the list query when ?mmp_folder_id is present in the URL.
 *  4. Enqueues the compiled React bundle on the list screen.
 *  5. Injects `window.MmpConfig` + React portal mount points into the footer.
 *  6. Injects bridge JS that repositions the sidebar portal next to the table.
 *
 * Developer extension point — add/remove post types programmatically:
 *
 *   add_filter( 'mmp_post_type_folders', fn( $types ) => [ ...$types, 'portfolio' ] );
 *
 * @package MMP\PostType
 * @since   1.0.0
 */
class PostTypeIntegration {

    /** @var string[] Final list of CPT slugs that receive folder support. */
    private array $enabledTypes;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FolderService    $folderService,
    ) {
        $settings = (array) get_option( 'mmp_settings', [] );
        $saved    = is_array( $settings['enabled_post_types'] ?? null )
            ? array_filter( $settings['enabled_post_types'], 'is_string' )
            : [];

        /**
         * Filters the post types that receive MMP folder support.
         *
         * Seeded from the settings page checkboxes. Developers can append or
         * remove types programmatically without touching the database.
         *
         * @since 1.0.0
         *
         * @param string[] $post_types Post type slugs.
         */
        $this->enabledTypes = array_values(
            (array) apply_filters( 'mmp_post_type_folders', array_values( $saved ) )
        );
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        if ( empty( $this->enabledTypes ) ) {
            return;
        }

        // Extend `mmp_folder` taxonomy to cover enabled post types.
        add_filter( 'mmp_supported_post_types', [ $this, 'addEnabledTypes' ] );

        // List table: column header + cell renderer per post type.
        foreach ( $this->enabledTypes as $postType ) {
            add_filter( "manage_{$postType}_posts_columns",       [ $this, 'addFolderColumn' ] );
            add_action( "manage_{$postType}_posts_custom_column", [ $this, 'renderFolderColumn' ], 10, 2 );
        }

        // Filter CPT list query when a folder is selected.
        add_action( 'pre_get_posts', [ $this, 'filterByFolder' ] );

        // Assets, mount points, and bridge on edit.php only.
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueueAssets' ] );
        add_action( 'admin_footer-edit.php',  [ $this, 'injectSidebarMount' ] );
        add_action( 'admin_footer-edit.php',  [ $this, 'injectBridge' ] );
    }

    // -------------------------------------------------------------------------
    // Taxonomy extension
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $types
     * @return string[]
     */
    public function addEnabledTypes( array $types ): array {
        return array_unique( array_merge( $types, $this->enabledTypes ) );
    }

    // -------------------------------------------------------------------------
    // List table column
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string> $columns
     * @return array<string, string>
     */
    public function addFolderColumn( array $columns ): array {
        $columns['mmp_folder'] = __( 'Folder', 'media-management-platform');
        return $columns;
    }

    /**
     * @param string $column  Column machine name.
     * @param int    $postId  Current post ID.
     */
    public function renderFolderColumn( string $column, int $postId ): void {
        if ( 'mmp_folder' !== $column ) {
            return;
        }

        $terms = get_the_terms( $postId, FolderTaxonomy::TAXONOMY );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            echo '<span class="mmp-uncategorized">&mdash;</span>';
            return;
        }

        $names = array_map( static fn( \WP_Term $t ) => esc_html( $t->name ), $terms );
        echo '<span class="mmp-folder-label">' . implode( ', ', $names ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $names are esc_html() escaped via array_map above
    }

    // -------------------------------------------------------------------------
    // Query filter
    // -------------------------------------------------------------------------

    /**
     * Applies the folder filter to the CPT list query when ?mmp_folder_id is set.
     *
     *   mmp_folder_id absent / -1 → no filter
     *   mmp_folder_id = 0         → Uncategorized (NOT EXISTS)
     *   mmp_folder_id > 0         → specific folder
     */
    public function filterByFolder( \WP_Query $query ): void {
        if ( ! $query->is_main_query() || ! is_admin() ) {
            return;
        }

        $postType = (string) $query->get( 'post_type' );

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $folderId = isset( $_GET['mmp_folder_id'] ) ? (int) $_GET['mmp_folder_id'] : -1;
        // phpcs:enable

        if ( -1 === $folderId ) {
            return;
        }

        if ( 0 === $folderId ) {
            $query->set( 'tax_query', [
                [
                    'taxonomy' => FolderTaxonomy::TAXONOMY,
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                ],
            ] );
            return;
        }

        $query->set( 'tax_query', [
            [
                'taxonomy' => FolderTaxonomy::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => [ $folderId ],
                'operator' => 'IN',
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
    // -------------------------------------------------------------------------

    public function enqueueAssets( string $hook ): void {
        if ( 'edit.php' !== $hook ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $postType = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

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

    // -------------------------------------------------------------------------
    // React mount points + MmpConfig
    // -------------------------------------------------------------------------

    /**
     * Outputs hidden portal divs and `window.MmpConfig` into the edit.php footer.
     * Mirrors MediaLibraryIntegration::injectSidebarMount().
     */
    public function injectSidebarMount(): void {
        $screen = get_current_screen();

        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        $postType = $screen->post_type;

        if ( ! in_array( $postType, $this->enabledTypes, true ) ) {
            return;
        }

        $userId      = get_current_user_id();
        $settings    = (array) get_option( 'mmp_settings', [] );
        $folderMode  = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';
        $treeUserId  = ( 'per_user' === $folderMode ) ? $userId : 0;
        $initialTree = $this->folderService->getTree( $treeUserId );
        $userPrefs   = $this->getUserPrefs( $userId );

        $config = [
            'restUrl'     => rest_url( 'mmp/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'postType'    => $postType,
            'initialTree' => $initialTree,
            'userPrefs'   => $userPrefs,
            'licenceTier' => 'pro',
        ];

        echo '<div id="mmp-root" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-sidebar-portal" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-breadcrumb-root" style="display:none;"></div>' . "\n";
        echo '<div id="mmp-toolbar-root" style="display:none;"></div>' . "\n";
        echo '<script>window.MmpConfig = ' . wp_json_encode( $config ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
        echo '<style>
#mmp-sidebar-portal { height: 100%; overflow: hidden; flex-shrink: 0; }
#mmp-cpt-layout { display: flex; flex-direction: row; align-items: flex-start; }
#mmp-cpt-content { flex: 1; min-width: 0; overflow-x: auto; }
</style>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Bridge JS
    // -------------------------------------------------------------------------

    /**
     * Injects the JavaScript that repositions #mmp-sidebar-portal next to the
     * CPT list table, turning the .wrap into a flex-row layout.
     *
     * The CPT list screen DOM at rest:
     *   .wrap
     *     h1
     *     <search / bulk forms>
     *     form#posts-filter
     *       .tablenav.top
     *       table.wp-list-table
     *       .tablenav.bottom
     *
     * After bridge runs:
     *   .wrap
     *     h1
     *     #mmp-cpt-layout  (flex-row)
     *       #mmp-sidebar-portal  (React FolderSidebar)
     *       #mmp-cpt-content     (flex-1)
     *         form#posts-filter
     */
    public function injectBridge(): void {
        $screen = get_current_screen();

        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        if ( ! in_array( $screen->post_type, $this->enabledTypes, true ) ) {
            return;
        }
        ?>
<script>
(function () {
    'use strict';

    var placed   = false;
    var attempts = 0;
    var MAX_ATTEMPTS = 20;

    function positionSidebar() {
        if (placed) return;

        var sidebarPortal = document.getElementById('mmp-sidebar-portal');
        if (!sidebarPortal) return;

        var postsFilter = document.getElementById('posts-filter');
        if (!postsFilter) return;

        var wrap = postsFilter.parentNode;
        if (!wrap) return;

        // Already wired up.
        if (document.getElementById('mmp-cpt-layout')) {
            sidebarPortal.style.cssText = 'display:block;flex-shrink:0;width:220px;min-height:500px;border-right:1px solid #e2e8f0;overflow:hidden;position:relative;background:#fff;';
            placed = true;
            return;
        }

        // Build flex layout wrapper.
        var layout = document.createElement('div');
        layout.id  = 'mmp-cpt-layout';
        layout.style.cssText = 'display:flex;flex-direction:row;align-items:flex-start;';

        // Content column (holds the existing posts-filter form).
        var content = document.createElement('div');
        content.id  = 'mmp-cpt-content';
        content.style.cssText = 'flex:1;min-width:0;overflow-x:auto;';

        // Style the sidebar portal.
        sidebarPortal.style.cssText = 'display:block;flex-shrink:0;width:220px;min-height:500px;border-right:1px solid #e2e8f0;overflow:hidden;position:relative;background:#fff;';

        // Insert the layout wrapper where postsFilter currently is.
        wrap.insertBefore(layout, postsFilter);

        // Move postsFilter inside the content column.
        content.appendChild(postsFilter);

        // Assemble: sidebar | content.
        layout.appendChild(sidebarPortal);
        layout.appendChild(content);

        placed = true;
    }

    function tryBoot() {
        positionSidebar();
        if (!placed && attempts < MAX_ATTEMPTS) {
            attempts++;
            setTimeout(tryBoot, 300);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryBoot);
    } else {
        tryBoot();
    }
}());
</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieves user preferences from wp_mmp_user_prefs.
     *
     * @param  int $userId
     * @return array<string, mixed>
     */
    private function getUserPrefs( int $userId ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT folder_id, sort_files, sort_dir, sidebar_w, ui_theme
                 FROM {$wpdb->prefix}mmp_user_prefs
                 WHERE user_id = %d
                 LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if ( null === $row ) {
            return $this->defaultPrefs();
        }

        return [
            'folder_id'  => isset( $row['folder_id'] ) ? (int) $row['folder_id'] : null,
            'sort_files' => (string) ( $row['sort_files'] ?? 'date' ),
            'sort_dir'   => (string) ( $row['sort_dir']   ?? 'desc' ),
            'sidebar_w'  => (int)    ( $row['sidebar_w']  ?? 220 ),
            'ui_theme'   => (string) ( $row['ui_theme']   ?? 'default' ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPrefs(): array {
        return [
            'folder_id'  => null,
            'sort_files' => 'date',
            'sort_dir'   => 'desc',
            'sidebar_w'  => 220,
            'ui_theme'   => 'default',
        ];
    }
}
