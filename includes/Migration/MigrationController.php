<?php

declare(strict_types=1);

namespace MMP\Migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Handles admin HTTP actions for the migration tool.
 *
 * admin-post.php actions:
 *   mmp_migration_start   — starts a background import for a given importer slug
 *   mmp_migration_cancel  — cancels a running import
 *
 * REST endpoint (for progress polling):
 *   GET /wp-json/mmp/v1/migration/progress?slug=filebird
 *
 * @package MMP\Migration
 * @since   1.0.0
 */
class MigrationController {

    private const CAPABILITY   = 'manage_mmp_settings';
    private const NONCE_ACTION = 'mmp_migration';
    private const NONCE_FIELD  = 'mmp_migration_nonce';
    private const REDIRECT_TAB = 'upload.php?page=mmp-settings&tab=migration';
    private const REST_NS      = 'mmp/v1';

    public function __construct(
        private readonly ImportManager $importManager,
    ) {}

    /**
     * Register all hooks. Call once on plugins_loaded.
     */
    public function register(): void {
        add_action( 'admin_post_mmp_migration_start',  [ $this, 'handleStart' ] );
        add_action( 'admin_post_mmp_migration_cancel', [ $this, 'handleCancel' ] );
        add_action( 'rest_api_init',                   [ $this, 'registerRestRoute' ] );
    }

    // -------------------------------------------------------------------------
    // admin-post.php handlers
    // -------------------------------------------------------------------------

    public function handleStart(): void {
        $this->checkPermission();

        $slug = $this->getSlugFromRequest();
        $this->verifyNonce( $slug );

        $started = $this->importManager->startImport( $slug );

        $notice = $started
            ? __( 'Migration started. Progress will update below.', 'media-management-platform')
            : __( 'Could not start migration. It may already be running or the importer is unknown.', 'media-management-platform');

        $this->redirect( $slug, $started ? 'info' : 'error', $notice );
    }

    public function handleCancel(): void {
        $this->checkPermission();

        $slug = $this->getSlugFromRequest();
        $this->verifyNonce( $slug );

        $this->importManager->cancelImport( $slug );

        $this->redirect( $slug, 'info', __( 'Migration cancelled.', 'media-management-platform') );
    }

    // -------------------------------------------------------------------------
    // REST route
    // -------------------------------------------------------------------------

    public function registerRestRoute(): void {
        register_rest_route(
            self::REST_NS,
            '/migration/progress',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restGetProgress' ],
                'permission_callback' => static fn() => current_user_can( self::CAPABILITY ),
                'args'                => [
                    'slug' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    /**
     * Returns the current ImportProgress for the requested importer slug as JSON.
     */
    public function restGetProgress( \WP_REST_Request $request ): \WP_REST_Response {
        $slug     = (string) $request->get_param( 'slug' );
        $progress = $this->importManager->getProgress( $slug );

        return new \WP_REST_Response( $progress->toArray(), 200 );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function checkPermission(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'media-management-platform') );
        }
    }

    private function getSlugFromRequest(): string {
        return sanitize_key( (string) ( $_POST['mmp_importer_slug'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by verifyNonce() before use
    }

    private function verifyNonce( string $slug ): void {
        $nonce = sanitize_text_field( wp_unslash( (string) ( $_POST[ self::NONCE_FIELD ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the nonce extraction for verification
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $slug ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'media-management-platform') );
        }
    }

    private function redirect( string $slug, string $status, string $message ): never {
        $url = add_query_arg(
            [
                'mmp_migration_slug'   => $slug,
                'mmp_notice'           => rawurlencode( $message ),
                'mmp_status'           => $status,
            ],
            admin_url( self::REDIRECT_TAB )
        );

        wp_safe_redirect( $url );
        exit;
    }
}
