<?php

declare(strict_types=1);

namespace MMP\Gallery;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;

/**
 * Registers the "Media › Shortcode Builder" admin page (S39).
 *
 * Outputs <div id="mmp-builder-root"> and injects MmpConfig so the shared
 * mmp-admin.js bundle can mount the ShortcodeBuilderPage React wizard.
 *
 * Also enqueues the gallery CSS on this page so the live preview renders
 * correctly inside the React component.
 *
 * @package MMP\Gallery
 * @since   1.0.0
 */
class ShortcodeBuilderPage {

    private const PAGE_SLUG  = 'mmp-shortcode-builder';
    private const CAPABILITY = 'upload_files';

    public function __construct(
        private readonly FolderRepository $folderRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        $hook = add_submenu_page(
            'upload.php',
            __( 'Shortcode Builder', 'media-management-platform'),
            __( 'Shortcode Builder', 'media-management-platform'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );

        add_action( 'admin_enqueue_scripts', function ( string $hookSuffix ) use ( $hook ): void {
            if ( $hookSuffix !== $hook ) {
                return;
            }
            $this->enqueueAssets();
        } );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        $config = $this->buildConfig();
        ?>
        <div class="wrap">
            <div id="mmp-builder-root"></div>
        </div>
        <script>window.MmpConfig = <?php echo wp_json_encode( $config ); ?>;</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
        // Main React bundle.
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

        // Gallery CSS — needed so the live preview renders correctly.
        wp_enqueue_style(
            'mmp-gallery-block',
            MMP_URL . 'admin/assets/css/block-mmp-gallery.css',
            [],
            MMP_VERSION
        );

        // Carousel JS — needed so the live preview works for carousel layout.
        wp_enqueue_script(
            'mmp-gallery-carousel',
            MMP_URL . 'admin/assets/js/mmp-gallery-carousel.js',
            [],
            MMP_VERSION,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array {
        $userId   = get_current_user_id();
        $settings = (array) get_option( 'mmp_settings', [] );
        $mode     = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';

        return [
            'restUrl'     => rest_url( 'mmp/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $mode,
            'initialTree' => $this->folderRepository->getTree( $mode === 'global' ? 0 : $userId ),
            'userPrefs'   => [
                'folder_id'  => null,
                'sort_files' => 'date',
                'sort_dir'   => 'desc',
                'sidebar_w'  => 240,
                'ui_theme'   => 'default',
            ],
            'licenceTier' => 'free',
            'page'        => 'shortcode-builder',
        ];
    }
}
