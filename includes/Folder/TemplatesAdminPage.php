<?php

declare(strict_types=1);

namespace MMP\Folder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Registers the "Media › Folder Templates" admin page (S38).
 *
 * Outputs <div id="mmp-templates-root"> and the MmpConfig global so the
 * shared mmp-admin.js bundle can mount the FolderTemplatesPage React app.
 *
 * @package MMP\Folder
 * @since   1.0.0
 */
class TemplatesAdminPage {

    private const PAGE_SLUG  = 'mmp-folder-templates';
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
            __( 'Folder Templates', 'media-management-platform'),
            __( 'Folder Templates', 'media-management-platform'),
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
            <div id="mmp-templates-root"></div>
        </div>
        <script>window.MmpConfig = <?php echo wp_json_encode( $config ); ?>;</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    private function enqueueAssets(): void {
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
            'page'        => 'folder-templates',
        ];
    }
}
