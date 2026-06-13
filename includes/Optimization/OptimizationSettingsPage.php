<?php

declare(strict_types=1);

namespace MMP\Optimization;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Admin settings page for CDN + Image Optimization (S56).
 *
 * Registered under Media › Optimization.
 * Option key: mmp_optimization_settings
 *
 * Settings:
 *   auto_webp     bool    Auto-convert uploads to WebP
 *   auto_avif     bool    Auto-convert uploads to AVIF (requires Imagick with AVIF support)
 *   quality       int     Compression quality 1–100 (default 82)
 *   cdn_provider  string  'none'|'cloudflare'|'bunnycdn'|'cloudfront'|'custom'
 *   cdn_base_url  string  CDN base URL (e.g. https://cdn.example.com)
 *   lazy_load     bool    Add loading="lazy" to content images
 *
 * @package MMP\Optimization
 * @since   1.0.0
 */
class OptimizationSettingsPage {

    private const PAGE_SLUG  = 'mmp-optimization';
    private const SECTION_ID = 'mmp_opt_section';
    private const CAPABILITY = 'manage_mmp_settings';

    public function __construct(
        private readonly ImageOptimizer $optimizer,
    ) {}

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        add_submenu_page(
            'upload.php',
            __( 'MMP Optimization', 'media-management-platform'),
            __( 'Optimization', 'media-management-platform'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // -------------------------------------------------------------------------
    // Settings API
    // -------------------------------------------------------------------------

    public function registerSettings(): void {
        register_setting(
            self::PAGE_SLUG,
            ImageOptimizer::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => ImageOptimizer::defaults(),
            ]
        );

        add_settings_section( self::SECTION_ID, '', '__return_false', self::PAGE_SLUG );

        add_settings_field( 'mmp_opt_auto_webp',    __( 'Auto WebP conversion', 'media-management-platform'),  [ $this, 'renderAutoWebp' ],   self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_opt_auto_avif',    __( 'Auto AVIF conversion', 'media-management-platform'),  [ $this, 'renderAutoAvif' ],   self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_opt_quality',      __( 'Compression quality', 'media-management-platform'),   [ $this, 'renderQuality' ],    self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_opt_cdn_provider', __( 'CDN provider', 'media-management-platform'),          [ $this, 'renderCdnProvider' ],self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_opt_cdn_base_url', __( 'CDN base URL', 'media-management-platform'),          [ $this, 'renderCdnBaseUrl' ], self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_opt_lazy_load',    __( 'Lazy-load content images', 'media-management-platform'), [ $this, 'renderLazyLoad' ], self::PAGE_SLUG, self::SECTION_ID );
    }

    public function sanitize( array $raw ): array {
        $prev = (array) get_option( ImageOptimizer::OPTION_NAME, [] );

        return [
            'auto_webp'    => ! empty( $raw['auto_webp'] ),
            'auto_avif'    => ! empty( $raw['auto_avif'] ),
            'quality'      => max( 1, min( 100, (int) ( $raw['quality'] ?? 82 ) ) ),
            'cdn_provider' => in_array( $raw['cdn_provider'] ?? '', [ 'none', 'cloudflare', 'bunnycdn', 'cloudfront', 'custom' ], true )
                              ? $raw['cdn_provider']
                              : ( $prev['cdn_provider'] ?? 'none' ),
            'cdn_base_url' => esc_url_raw( trim( (string) ( $raw['cdn_base_url'] ?? '' ) ) ),
            'lazy_load'    => ! empty( $raw['lazy_load'] ),
        ];
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    public function renderAutoWebp(): void {
        $s = $this->optimizer->getSettings();
        $c = checked( $s['auto_webp'], true, false );
        echo "<label><input type='checkbox' name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[auto_webp]' value='1' {$c}> " // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $c is the return value of checked(), a trusted WP core function that outputs only ' checked' or ''
           . esc_html__( 'Convert new uploads to WebP on upload (GD or Imagick)', 'media-management-platform')
           . '</label>';
    }

    public function renderAutoAvif(): void {
        $s       = $this->optimizer->getSettings();
        $c       = checked( $s['auto_avif'], true, false );
        $support = extension_loaded( 'imagick' ) && ! empty( \Imagick::queryFormats( 'AVIF' ) );
        $note    = $support
            ? ''
            : ' <em style="color:#888">' . esc_html__( '(Imagick + AVIF support required — not detected)', 'media-management-platform') . '</em>';
        echo "<label><input type='checkbox' name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[auto_avif]' value='1' {$c}" // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $c is from checked() (WP core, safe); $note contains esc_html__() output wrapped in hardcoded <em> tags
           . ( $support ? '' : ' disabled' )
           . '> ' . esc_html__( 'Convert new uploads to AVIF on upload (requires Imagick)', 'media-management-platform') . $note . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $note is pre-escaped via esc_html__() wrapped in hardcoded safe HTML
    }

    public function renderQuality(): void {
        $s = $this->optimizer->getSettings();
        $q = (int) $s['quality'];
        echo "<input type='number' name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[quality]' value='" . esc_attr( (string) $q ) . "' min='1' max='100' style='width:70px'>"
           . "<p class='description'>" . esc_html__( '1 (smallest) – 100 (lossless). Recommended: 75–85.', 'media-management-platform') . '</p>';
    }

    public function renderCdnProvider(): void {
        $s        = $this->optimizer->getSettings();
        $current  = (string) $s['cdn_provider'];
        $options  = [
            'none'        => __( 'None (disabled)', 'media-management-platform'),
            'cloudflare'  => __( 'Cloudflare CDN', 'media-management-platform'),
            'bunnycdn'    => __( 'BunnyCDN', 'media-management-platform'),
            'cloudfront'  => __( 'AWS CloudFront', 'media-management-platform'),
            'custom'      => __( 'Custom URL', 'media-management-platform'),
        ];

        echo "<select name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[cdn_provider]'>";
        foreach ( $options as $value => $label ) {
            $sel = selected( $current, $value, false );
            echo "<option value='" . esc_attr( $value ) . "' " . esc_attr( $sel ) . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns safe HTML attribute
        }
        echo '</select>';
    }

    public function renderCdnBaseUrl(): void {
        $s = $this->optimizer->getSettings();
        echo "<input type='url' name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[cdn_base_url]' value='"
           . esc_attr( (string) $s['cdn_base_url'] )
           . "' class='regular-text' placeholder='https://cdn.example.com'>"
           . "<p class='description'>" . esc_html__( 'Leave blank to disable CDN rewriting.', 'media-management-platform') . '</p>';
    }

    public function renderLazyLoad(): void {
        $s = $this->optimizer->getSettings();
        $c = checked( $s['lazy_load'], true, false );
        echo "<label><input type='checkbox' name='" . esc_attr( ImageOptimizer::OPTION_NAME ) . "[lazy_load]' value='1' " . esc_attr( $c ) . '> ' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $c is output of WordPress checked() function
            . esc_html__( 'Add loading="lazy" to images in post content that are missing the attribute', 'media-management-platform')
            . '</label>';
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'media-management-platform') );
        }

        $stats = $this->optimizer->getStats();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MMP Optimization', 'media-management-platform'); ?></h1>

            <?php settings_errors(); ?>

            <!-- Speed Dashboard -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:20px 24px;margin:16px 0 24px">
                <h2 style="margin-top:0;font-size:15px;color:#334155"><?php esc_html_e( 'Speed Dashboard', 'media-management-platform'); ?></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px">
                    <?php
                    $panels = [
                        [ 'label' => __( 'Images optimized', 'media-management-platform'), 'value' => number_format_i18n( $stats['total'] ) ],
                        [ 'label' => __( 'WebP converted', 'media-management-platform'),   'value' => number_format_i18n( $stats['webp_count'] ) ],
                        [ 'label' => __( 'AVIF converted', 'media-management-platform'),   'value' => number_format_i18n( $stats['avif_count'] ) ],
                        [ 'label' => __( 'Total savings', 'media-management-platform'),    'value' => size_format( $stats['total_savings_bytes'], 1 ) ],
                        [ 'label' => __( 'Conversion rate', 'media-management-platform'),  'value' => $stats['conversion_rate'] . '%' ],
                    ];
                    foreach ( $panels as $p ) : ?>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px 16px;text-align:center">
                            <div style="font-size:22px;font-weight:700;color:#1e293b"><?php echo esc_html( $p['value'] ); ?></div>
                            <div style="font-size:12px;color:#64748b;margin-top:2px"><?php echo esc_html( $p['label'] ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="font-size:12px;color:#94a3b8;margin:12px 0 0">
                    <?php esc_html_e( 'Stats update as images are converted. Run a batch via WP-CLI: wp mmp optimize', 'media-management-platform'); ?>
                </p>
            </div>

            <!-- Settings form -->
            <form method="post" action="options.php">
                <?php settings_fields( self::PAGE_SLUG ); ?>
                <?php do_settings_sections( self::PAGE_SLUG ); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
