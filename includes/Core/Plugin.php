<?php

declare(strict_types=1);

namespace MMP\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Main plugin class — singleton service container and boot loader.
 *
 * Responsibilities:
 *  - Hold registered service instances.
 *  - Load each subsystem in the correct order.
 *  - Provide a central `get()` accessor for other classes.
 */
final class Plugin {

    private static ?Plugin $instance = null;

    /** @var array<string, object> Registered service instances. */
    private array $services = [];

    private bool $booted = false;

    private function __construct() {}

    public static function getInstance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot all subsystems. Called once on `plugins_loaded`.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        $this->runUpgrader();
        $this->ensureCapabilities();
        $this->registerServices();
    }

    /**
     * Retrieve a registered service by key.
     *
     * @template T of object
     * @param  class-string<T> $key
     * @return T
     */
    public function get( string $key ): object {
        if ( ! isset( $this->services[ $key ] ) ) {
            throw new \RuntimeException( "MMP service not registered: {$key}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return $this->services[ $key ]; // @phpstan-ignore-line
    }

    /**
     * Register (bind) a service instance.
     */
    public function bind( string $key, object $instance ): void {
        $this->services[ $key ] = $instance;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Ensures the MMP custom capabilities are assigned to administrator and
     * editor roles on every boot. This is a no-op if they are already set,
     * but it repairs installs where the activation hook ran before the
     * addCapabilities() code existed.
     */
    private function ensureCapabilities(): void {
        $roles = [
            'administrator' => [ 'manage_mmp_folders', 'manage_mmp_settings' ],
            'editor'        => [ 'manage_mmp_folders' ],
        ];

        foreach ( $roles as $roleName => $caps ) {
            $role = get_role( $roleName );
            if ( ! $role ) {
                continue;
            }
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

    private function runUpgrader(): void {
        Upgrader::maybeUpgrade();
    }

    /**
     * Instantiate and wire up every subsystem.
     * Services are registered here so they are available via `Plugin::get()`.
     */
    private function registerServices(): void {
        // --- Core repositories & services (Phase 1) ---
        $folderRepo    = new \MMP\Folder\FolderRepository();
        $folderService = new \MMP\Folder\FolderService( $folderRepo );

        $this->bind( \MMP\Folder\FolderRepository::class, $folderRepo );
        $this->bind( \MMP\Folder\FolderService::class,    $folderService );

        // --- Media ---
        $mediaRepo    = new \MMP\Media\MediaRepository();
        $mediaService = new \MMP\Media\MediaService( $mediaRepo );

        $this->bind( \MMP\Media\MediaRepository::class, $mediaRepo );
        $this->bind( \MMP\Media\MediaService::class,    $mediaService );

        // --- Taxonomy ---
        ( new \MMP\Taxonomy\FolderTaxonomy() )->register();

        // --- Folder utilities ---
        $zipService = new \MMP\Folder\ZipService( $folderRepo, $mediaRepo );

        $this->bind( \MMP\Folder\ZipService::class, $zipService );

        // --- Batch Meta ---
        $batchMetaService = new \MMP\Media\BatchMetaService();
        $this->bind( \MMP\Media\BatchMetaService::class, $batchMetaService );

        // --- Folder Templates ---
        $folderTemplate = new \MMP\Folder\FolderTemplate();
        $this->bind( \MMP\Folder\FolderTemplate::class, $folderTemplate );

        add_action( 'rest_api_init', function () use ( $folderTemplate ): void {
            ( new \MMP\API\FolderTemplateRestController( $folderTemplate ) )->register();
        } );

        // --- Duplicate Detection ---
        $duplicateDetector = new \MMP\Media\DuplicateDetector();
        $duplicateDetector->register();
        $this->bind( \MMP\Media\DuplicateDetector::class, $duplicateDetector );

        add_action( 'rest_api_init', function () use ( $duplicateDetector ): void {
            ( new \MMP\API\DuplicateRestController( $duplicateDetector ) )->register();
        } );

        // --- Gallery REST (preview for Shortcode Builder) ---
        add_action( 'rest_api_init', function () use ( $folderRepo ): void {
            ( new \MMP\API\GalleryRestController( $folderRepo ) )->register();
        } );

        // --- Document Library REST (AJAX browsing for [mmp_documents]) ---
        add_action( 'rest_api_init', function () use ( $folderRepo ): void {
            ( new \MMP\API\DocumentRestController( $folderRepo ) )->register();
        } );

        // --- Smart Tags (must be before AI Auto-Tagging — AiTaggingService depends on TagRepository) ---
        $tagRepo    = new \MMP\Tags\TagRepository();
        $tagService = new \MMP\Tags\TagService( $tagRepo );
        $tagService->register();

        $this->bind( \MMP\Tags\TagRepository::class, $tagRepo );
        $this->bind( \MMP\Tags\TagService::class,    $tagService );

        add_action( 'rest_api_init', function () use ( $tagRepo, $tagService ): void {
            ( new \MMP\Tags\TagRestController( $tagRepo, $tagService ) )->register();
        } );

        // --- Image Search Service (S58 — colour/orientation indexer) ---
        $imageSearchService = new \MMP\Search\ImageSearchService();
        $imageSearchService->register();
        $this->bind( \MMP\Search\ImageSearchService::class, $imageSearchService );

        // --- Advanced Search (S44) + AI Smart Search (S48) ---
        add_action( 'rest_api_init', function () use ( $folderRepo, $imageSearchService ): void {
            $searchService = new \MMP\Search\AdvancedSearchService( $folderRepo, $imageSearchService );
            $smartSearch   = new \MMP\AI\SmartSearchService( $searchService );
            ( new \MMP\API\AdvancedSearchRestController( $searchService, $smartSearch ) )->register();
        } );

        // --- AI Auto-Tagging (S47) ---
        $aiTaggingService = new \MMP\AI\AiTaggingService( $tagRepo, $folderRepo );
        $aiTaggingService->register();

        $this->bind( \MMP\AI\AiTaggingService::class, $aiTaggingService );

        add_action( 'rest_api_init', function () use ( $aiTaggingService ): void {
            ( new \MMP\API\AiTaggingRestController( $aiTaggingService ) )->register();
        } );

        // --- OCR (S49) ---
        $ocrService = new \MMP\AI\OcrService( $folderRepo );
        $ocrService->register();
        $this->bind( \MMP\AI\OcrService::class, $ocrService );

        // --- GraphQL API (WPGraphQL extension, S43) ---
        ( new \MMP\API\GraphQLSchema( $folderRepo, $folderService ) )->register();

        // --- REST API ---
        add_action( 'rest_api_init', function () use ( $folderService, $folderRepo, $mediaService, $zipService, $batchMetaService ): void {
            ( new \MMP\API\RestController( $folderService, $folderRepo, $mediaService, $zipService, $batchMetaService ) )->register();
        } );

        // --- WooCommerce Gallery Folder Sync ---
        ( new \MMP\WooCommerce\ProductGallerySync( $folderRepo ) )->register();

        // --- WooCommerce Media Folder Integration (sidebar in product media modal) ---
        ( new \MMP\WooCommerce\WooCommerceIntegration( $folderService ) )->register();

        // --- Gutenberg Gallery Block (registered on init, not admin-only) ---
        ( new \MMP\Gallery\GalleryBlock( $folderRepo ) )->register();

        // --- Gallery Shortcode (frontend + content, not admin-only) ---
        ( new \MMP\Gallery\GalleryShortcode( $folderRepo ) )->register();

        // --- Document Library Shortcode (frontend, not admin-only) ---
        ( new \MMP\Frontend\DocumentLibrary( $folderRepo ) )->register();

        // --- Upload Handler (runs outside is_admin — REST API uploads too) ---
        ( new \MMP\Upload\UploadHandler( $mediaRepo, $folderRepo ) )->register();

        // --- Migration Tool ---
        $importManager = new \MMP\Migration\ImportManager();
        $importManager->addImporter( 'filebird',   new \MMP\Migration\FileBirdImporter( $folderRepo ) );
        $importManager->addImporter( 'rml',        new \MMP\Migration\RealMediaLibraryImporter( $folderRepo ) );
        $importManager->addImporter( 'wicked',     new \MMP\Migration\WickedFoldersImporter( $folderRepo ) );
        $importManager->addImporter( 'happyfiles', new \MMP\Migration\HappyFilesImporter( $folderRepo ) );
        $importManager->register();

        $migrationController = new \MMP\Migration\MigrationController( $importManager );
        $migrationController->register();

        $this->bind( \MMP\Migration\ImportManager::class,      $importManager );
        $this->bind( \MMP\Migration\MigrationController::class, $migrationController );

        // --- CSV Import / Export ---
        $csvController = new \MMP\CSV\CsvController( $folderRepo );
        $csvController->register();

        $this->bind( \MMP\CSV\CsvController::class, $csvController );

        // --- Permissions System ---
        $permRepo         = new \MMP\Folder\PermissionRepository();
        $folderPermission = new \MMP\Folder\FolderPermission( $permRepo, $folderRepo );
        $folderPermission->register();

        $this->bind( \MMP\Folder\PermissionRepository::class, $permRepo );
        $this->bind( \MMP\Folder\FolderPermission::class,     $folderPermission );

        add_action( 'rest_api_init', function () use ( $folderPermission, $permRepo, $folderRepo ): void {
            ( new \MMP\API\PermissionRestController( $folderPermission, $permRepo, $folderRepo ) )->register();
        } );

        // --- Multilingual compatibility (S26) ---
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            ( new \MMP\Compat\WpmlIntegration() )->register();
        } elseif ( defined( 'POLYLANG_VERSION' ) ) {
            ( new \MMP\Compat\PolylangIntegration() )->register();
        } else {
            // No multilingual plugin: still enqueue RTL stylesheet when needed.
            add_action( 'admin_enqueue_scripts', static function (): void {
                if ( is_rtl() ) {
                    wp_enqueue_style(
                        'mmp-rtl',
                        plugin_dir_url( MMP_PLUGIN_FILE ) . 'admin/assets/css/rtl.css',
                        [ 'mmp-admin' ],
                        MMP_VERSION
                    );
                }
            } );
        }

        // --- Page Builder Compatibility (media modal bridge) ---
        ( new \MMP\Compat\PageBuilderCompat( $folderRepo, $folderService ) )->register();

        // --- Page Builder Gallery Integrations (S42) ---

        // Elementor widget.
        add_action( 'elementor/widgets/register', function ( $widgetsManager ) use ( $folderRepo ): void {
            $widgetsManager->register( new \MMP\Compat\ElementorGalleryWidget( [], null, $folderRepo ) );
        } );

        // Divi module.
        add_action( 'et_builder_ready', function (): void {
            if ( class_exists( 'ET_Builder_Module' ) ) {
                new \MMP\Compat\DiviGalleryModule();
            }
        } );

        // Beaver Builder module.
        add_action( 'init', function () use ( $folderRepo ): void {
            if ( class_exists( 'FLBuilder' ) ) {
                \MMP\Compat\BeaverBuilderGalleryModule::register( $folderRepo );
            }
        } );

        // WPBakery element.
        add_action( 'vc_before_init', function () use ( $folderRepo ): void {
            ( new \MMP\Compat\WPBakeryGalleryElement( $folderRepo ) )->register();
        } );

        // ACF Folder Picker field type.
        add_action( 'acf/include_field_types', function () use ( $folderRepo ): void {
            \MMP\Compat\AcfFolderPickerField::register( $folderRepo );
        } );

        // Bricks Builder element.
        add_action( 'init', function () use ( $folderRepo ): void {
            if ( ! class_exists( '\Bricks\Element' ) ) {
                return;
            }
            \MMP\Compat\BricksGalleryElement::register( $folderRepo );
        } );

        // --- Image Optimization + CDN + Lazy Loading (S56) ---
        $imageOptimizer = new \MMP\Optimization\ImageOptimizer();
        $imageOptimizer->register();
        $this->bind( \MMP\Optimization\ImageOptimizer::class, $imageOptimizer );

        $optSettings = $imageOptimizer->getSettings();

        // CDN URL rewriting (active on all requests, not just admin).
        if ( ! empty( $optSettings['cdn_base_url'] ) && $optSettings['cdn_provider'] !== 'none' ) {
            ( new \MMP\Optimization\CdnRewriter( (string) $optSettings['cdn_base_url'] ) )->register();
        }

        // Lazy-loader (active on frontend requests).
        if ( ! empty( $optSettings['lazy_load'] ) ) {
            ( new \MMP\Optimization\LazyLoader() )->register();
        }

        add_action( 'rest_api_init', function () use ( $imageOptimizer ): void {
            ( new \MMP\API\OptimizationRestController( $imageOptimizer ) )->register();
        } );

        // --- Client Sharing Portal (S59) ---
        $shareLinkRepo = new \MMP\Frontend\ShareLinkRepository();
        $shareLinkRepo->createTables();

        $clientPortal = new \MMP\Frontend\ClientPortal( $shareLinkRepo, $folderRepo );
        $clientPortal->register();

        $this->bind( \MMP\Frontend\ShareLinkRepository::class, $shareLinkRepo );
        $this->bind( \MMP\Frontend\ClientPortal::class, $clientPortal );

        add_action( 'rest_api_init', function () use ( $shareLinkRepo, $folderRepo ): void {
            ( new \MMP\API\ShareRestController( $shareLinkRepo, $folderRepo ) )->register();
        } );

        // --- Real Filesystem Mode (S57) ---
        $fileMover = new \MMP\Filesystem\FileMover();
        $fsSync    = new \MMP\Filesystem\RealFolderSync( $folderRepo, $fileMover );
        $fsSync->register();

        $this->bind( \MMP\Filesystem\FileMover::class,      $fileMover );
        $this->bind( \MMP\Filesystem\RealFolderSync::class, $fsSync );

        // --- Media Replacement System (S60) ---
        $mediaReplacer = new \MMP\Media\MediaReplacer( $fileMover );
        $mediaReplacer->createTable();
        $mediaReplacer->register();

        $this->bind( \MMP\Media\MediaReplacer::class, $mediaReplacer );

        add_action( 'rest_api_init', function () use ( $mediaReplacer ): void {
            ( new \MMP\API\ReplaceRestController( $mediaReplacer ) )->register();
        } );

        // --- Media Usage Tracker (S33) ---
        $usageTracker = new \MMP\Media\UsageTracker();
        $usageTracker->register();
        $this->bind( \MMP\Media\UsageTracker::class, $usageTracker );

        add_action( 'rest_api_init', function () use ( $usageTracker ): void {
            ( new \MMP\API\UsageRestController( $usageTracker ) )->register();
        } );

        // --- Media Analytics Dashboard (S35) ---
        $analyticsDashboard = new \MMP\Analytics\AnalyticsDashboard();
        $analyticsDashboard->register();
        $this->bind( \MMP\Analytics\AnalyticsDashboard::class, $analyticsDashboard );

        add_action( 'rest_api_init', function () use ( $analyticsDashboard ): void {
            ( new \MMP\API\AnalyticsRestController( $analyticsDashboard ) )->register();
        } );

        // --- Admin ---
        if ( is_admin() ) {
            ( new \MMP\AI\AiSettingsPage() )->register();
            ( new \MMP\Optimization\OptimizationSettingsPage( $imageOptimizer ) )->register();
            ( new \MMP\Filesystem\FilesystemSettingsPage() )->register();

            $settingsPage = new \MMP\Settings\SettingsPage();
            $settingsPage->setImportManager( $importManager );
            $settingsPage->register();

            // --- Portal Settings Page ---
            ( new \MMP\Settings\PortalSettingsPage( $shareLinkRepo, $folderRepo ) )->register();

            // --- Duplicates Admin Page ---
            ( new \MMP\Media\DuplicatesAdminPage( $folderRepo ) )->register();

            // --- Folder Templates Admin Page ---
            ( new \MMP\Folder\TemplatesAdminPage( $folderRepo ) )->register();

            // --- Shortcode Builder Admin Page ---
            ( new \MMP\Gallery\ShortcodeBuilderPage( $folderRepo ) )->register();

            // --- Media Library Integration ---
            ( new \MMP\Media\MediaLibraryIntegration( $mediaRepo, $folderRepo, $folderService, $usageTracker ) )->register();

            // --- Post Type Folder Integration ---
            ( new \MMP\PostType\PostTypeIntegration( $folderRepo, $folderService ) )->register();
        }

        // --- WP-CLI ---
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'mmp', \MMP\CLI\MmpCommand::class );
        }

        /**
         * Fires after all MMP services have been registered.
         *
         * @param Plugin $plugin The plugin instance.
         */
        do_action( 'mmp_services_registered', $this );
    }
}
