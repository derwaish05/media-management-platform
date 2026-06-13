<?php

declare(strict_types=1);

namespace MMP\Media;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


use MMP\Folder\FolderRepository;

/**
 * Registers the "Media › Duplicates" admin page (S37).
 *
 * Outputs a single <div id="mmp-duplicates-root"> and the MmpConfig global
 * so the shared mmp-admin.js bundle can mount the DuplicatesPage React app
 * on this page.
 *
 * @package MMP\Media
 * @since   1.0.0
 */
class DuplicatesAdminPage {

    private const PAGE_SLUG  = 'mmp-duplicates';
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
            __( 'Duplicate Files', 'media-management-platform'),
            __( 'Duplicates', 'media-management-platform'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );

        // Enqueue assets only on this page.
        add_action( 'admin_enqueue_scripts', function ( string $hookSuffix ) use ( $hook ): void {
            if ( $hookSuffix !== $hook ) {
                return;
            }
            $this->enqueueAssets();
        } );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        // Inject MmpConfig so the React bundle boots correctly on this page.
        $config = $this->buildConfig();
        ?>
        <div class="wrap">
            <div id="mmp-duplicates-root"></div>
        </div>
        <script>window.MmpConfig = <?php echo wp_json_encode( $config ); ?>;</script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Asset enqueueing
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
    // Config builder
    // -------------------------------------------------------------------------

    /**
     * Build the MmpConfig object that the React bundle expects.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(): array {
        $userId   = get_current_user_id();
        $settings = (array) get_option( 'mmp_settings', [] );

        $folderMode = isset( $settings['folder_mode'] ) ? (string) $settings['folder_mode'] : 'global';

        return [
            'restUrl'     => rest_url( 'mmp/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'userId'      => $userId,
            'isAdmin'     => current_user_can( 'manage_options' ),
            'folderMode'  => $folderMode,
            'initialTree' => $this->folderRepository->getTree( $folderMode === 'global' ? 0 : $userId ),
            'userPrefs'   => [
                'folder_id'  => null,
                'sort_files' => 'date',
                'sort_dir'   => 'desc',
                'sidebar_w'  => 240,
                'ui_theme'   => 'default',
            ],
            'licenceTier' => 'free',
            'page'        => 'duplicates',
        ];
    }
}
