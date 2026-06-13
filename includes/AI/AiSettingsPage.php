<?php

declare(strict_types=1);

namespace MMP\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Admin settings page for AI Auto-Tagging (S47).
 *
 * Registered under Media › MMP AI Settings.
 * Option key: mmp_ai_settings
 *
 * Fields:
 *   provider              'none'|'aws'|'google'
 *   aws_access_key        string
 *   aws_secret_key        string  (not echoed in plain text after save)
 *   aws_region            string  e.g. 'us-east-1'
 *   google_api_key        string  (not echoed in plain text after save)
 *   confidence_threshold  float   0–100 (default 70)
 *   auto_assign           bool
 *   auto_assign_threshold float   0–100 (default 85)
 *
 * @package MMP\AI
 * @since   1.0.0
 */
class AiSettingsPage {

    public const OPTION_NAME = 'mmp_ai_settings';
    private const PAGE_SLUG  = 'mmp-ai-settings';
    private const SECTION_ID = 'mmp_ai_section';
    private const CAPABILITY = 'manage_mmp_settings';

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
            __( 'MMP AI Settings', 'media-management-platform'),
            __( 'MMP AI', 'media-management-platform'),
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
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );

        add_settings_section( self::SECTION_ID, '', '__return_false', self::PAGE_SLUG );

        add_settings_field( 'mmp_ai_provider',            __( 'AI provider', 'media-management-platform'),             [ $this, 'renderProvider' ],           self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_aws_access_key',      __( 'AWS access key ID', 'media-management-platform'),        [ $this, 'renderAwsAccessKey' ],        self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_aws_secret_key',      __( 'AWS secret access key', 'media-management-platform'),    [ $this, 'renderAwsSecretKey' ],        self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_aws_region',          __( 'AWS region', 'media-management-platform'),               [ $this, 'renderAwsRegion' ],           self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_google_api_key',      __( 'Google Vision API key', 'media-management-platform'),    [ $this, 'renderGoogleApiKey' ],        self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_confidence',          __( 'Confidence threshold (%)', 'media-management-platform'), [ $this, 'renderConfidenceThreshold' ], self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_auto_assign',         __( 'Auto-assign folder', 'media-management-platform'),       [ $this, 'renderAutoAssign' ],          self::PAGE_SLUG, self::SECTION_ID );
        add_settings_field( 'mmp_ai_auto_assign_thresh',  __( 'Auto-assign threshold (%)', 'media-management-platform'),[ $this, 'renderAutoAssignThreshold' ], self::PAGE_SLUG, self::SECTION_ID );
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    public function renderProvider(): void {
        $current = (string) $this->get()['provider'];
        $options = [
            'none'   => __( 'Disabled', 'media-management-platform'),
            'aws'    => __( 'AWS Rekognition', 'media-management-platform'),
            'google' => __( 'Google Cloud Vision', 'media-management-platform'),
        ];
        foreach ( $options as $value => $label ) {
            printf(
                '<label style="display:block;margin-bottom:6px;"><input type="radio" name="%s[provider]" value="%s" %s> %s</label>',
                esc_attr( self::OPTION_NAME ),
                esc_attr( $value ),
                checked( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '<p class="description">' . esc_html__( 'Select the AI service used for automatic image labelling on upload.', 'media-management-platform') . '</p>';
    }

    public function renderAwsAccessKey(): void {
        printf(
            '<input type="text" name="%s[aws_access_key]" value="%s" class="regular-text" autocomplete="off">',
            esc_attr( self::OPTION_NAME ),
            esc_attr( (string) $this->get()['aws_access_key'] )
        );
    }

    public function renderAwsSecretKey(): void {
        $saved = (string) $this->get()['aws_secret_key'];
        printf(
            '<input type="password" name="%s[aws_secret_key]" value="%s" class="regular-text" autocomplete="new-password"%s>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $saved ),
            $saved !== '' ? ' placeholder="' . esc_attr__( 'Leave blank to keep the saved key', 'media-management-platform') . '"' : ''
        );
    }

    public function renderAwsRegion(): void {
        printf(
            '<input type="text" name="%s[aws_region]" value="%s" class="regular-text" placeholder="us-east-1">',
            esc_attr( self::OPTION_NAME ),
            esc_attr( (string) $this->get()['aws_region'] )
        );
        echo '<p class="description">' . esc_html__( 'e.g. us-east-1, eu-west-1, ap-southeast-1', 'media-management-platform') . '</p>';
    }

    public function renderGoogleApiKey(): void {
        $saved = (string) $this->get()['google_api_key'];
        printf(
            '<input type="password" name="%s[google_api_key]" value="%s" class="regular-text" autocomplete="new-password"%s>',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $saved ),
            $saved !== '' ? ' placeholder="' . esc_attr__( 'Leave blank to keep the saved key', 'media-management-platform') . '"' : ''
        );
        echo '<p class="description">' . esc_html__( 'Enable the Cloud Vision API in Google Cloud Console before saving.', 'media-management-platform') . '</p>';
    }

    public function renderConfidenceThreshold(): void {
        printf(
            '<input type="number" name="%s[confidence_threshold]" value="%s" min="0" max="100" step="1" style="width:80px;"> %%',
            esc_attr( self::OPTION_NAME ),
            esc_attr( (string) (float) $this->get()['confidence_threshold'] )
        );
        echo '<p class="description">' . esc_html__( 'Labels with a confidence score below this value are discarded (default: 70).', 'media-management-platform') . '</p>';
    }

    public function renderAutoAssign(): void {
        printf(
            '<label><input type="checkbox" name="%s[auto_assign]" value="1" %s> %s</label>',
            esc_attr( self::OPTION_NAME ),
            checked( (bool) $this->get()['auto_assign'], true, false ),
            esc_html__( 'Automatically move the image to the best-matching folder when AI confidence is high enough', 'media-management-platform')
        );
    }

    public function renderAutoAssignThreshold(): void {
        printf(
            '<input type="number" name="%s[auto_assign_threshold]" value="%s" min="0" max="100" step="1" style="width:80px;"> %%',
            esc_attr( self::OPTION_NAME ),
            esc_attr( (string) (float) $this->get()['auto_assign_threshold'] )
        );
        echo '<p class="description">' . esc_html__( 'Minimum folder-match confidence required for automatic assignment (default: 85).', 'media-management-platform') . '</p>';
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    public function sanitize( mixed $input ): array {
        if ( ! is_array( $input ) ) {
            return self::defaults();
        }

        // Read the currently saved values so we can preserve secrets when the
        // password field is submitted blank.
        $existing = (array) get_option( self::OPTION_NAME, [] );
        $clean    = self::defaults();

        $clean['provider'] = in_array( $input['provider'] ?? '', [ 'none', 'aws', 'google' ], true )
            ? (string) $input['provider']
            : 'none';

        $clean['aws_access_key'] = sanitize_text_field( $input['aws_access_key'] ?? '' );

        $newSecret = trim( (string) ( $input['aws_secret_key'] ?? '' ) );
        $clean['aws_secret_key'] = $newSecret !== ''
            ? $newSecret
            : (string) ( $existing['aws_secret_key'] ?? '' );

        $clean['aws_region'] = sanitize_text_field( $input['aws_region'] ?? 'us-east-1' );
        if ( '' === $clean['aws_region'] ) {
            $clean['aws_region'] = 'us-east-1';
        }

        $newGoogleKey = trim( (string) ( $input['google_api_key'] ?? '' ) );
        $clean['google_api_key'] = $newGoogleKey !== ''
            ? $newGoogleKey
            : (string) ( $existing['google_api_key'] ?? '' );

        $clean['confidence_threshold']  = min( 100.0, max( 0.0, (float) ( $input['confidence_threshold']  ?? 70.0 ) ) );
        $clean['auto_assign']           = ! empty( $input['auto_assign'] );
        $clean['auto_assign_threshold'] = min( 100.0, max( 0.0, (float) ( $input['auto_assign_threshold'] ?? 85.0 ) ) );

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Page renderer
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'media-management-platform') );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'MMP AI Settings', 'media-management-platform'); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Choose an AI provider to automatically tag images on upload and suggest (or auto-assign) the best matching folder.', 'media-management-platform'); ?>
            </p>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::PAGE_SLUG );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Shared defaults (also consumed by AiTaggingService)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function defaults(): array {
        return [
            'provider'             => 'none',
            'aws_access_key'       => '',
            'aws_secret_key'       => '',
            'aws_region'           => 'us-east-1',
            'google_api_key'       => '',
            'confidence_threshold' => 70.0,
            'auto_assign'          => false,
            'auto_assign_threshold'=> 85.0,
        ];
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function get(): array {
        $saved = get_option( self::OPTION_NAME, [] );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
    }
}
