<?php

declare(strict_types=1);

namespace MMP\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Registers the MMP settings page under Media → MMP Settings.
 *
 * Five tabs, single wp_options key `mmp_settings`:
 *
 *   General    — folder_mode, default_sort
 *   Upload     — auto_assign_folder, allow_svg
 *   Post Types — enabled_post_types (CPT checkboxes)
 *   Advanced   — flush cache / reset settings / delete all data (action buttons)
 *
 * Plus two read-only tabs wired externally:
 *   Import / Export  (CSV)
 *   Migrate from Plugin
 *
 * @package MMP\Settings
 * @since   1.0.0
 */
class SettingsPage {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    private const OPTION_NAME   = 'mmp_settings';
    private const PAGE_SLUG     = 'mmp-settings';
    private const SECTION_GEN   = 'mmp_section_general';
    private const SECTION_UPL   = 'mmp_section_upload';
    private const SECTION_CPT   = 'mmp_section_cpt';
    private const CAPABILITY    = 'manage_mmp_settings';

    private const TAB_GENERAL    = 'general';
    private const TAB_UPLOAD     = 'upload';
    private const TAB_POST_TYPES = 'post-types';
    private const TAB_ADVANCED   = 'advanced';
    private const TAB_CSV        = 'csv';
    private const TAB_MIGRATION  = 'migration';

    private const VALID_TABS = [
        self::TAB_GENERAL,
        self::TAB_UPLOAD,
        self::TAB_POST_TYPES,
        self::TAB_ADVANCED,
        self::TAB_CSV,
        self::TAB_MIGRATION,
    ];

    /** Post types that must never appear in the CPT checkbox list. */
    private const EXCLUDED_POST_TYPES = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css',
        'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_global_styles', 'wp_template', 'wp_template_part',
        'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_block_binding',
    ];

    // -------------------------------------------------------------------------
    // External dependencies (set after construction)
    // -------------------------------------------------------------------------

    private ?\MMP\Migration\ImportManager $importManager = null;

    public function setImportManager( \MMP\Migration\ImportManager $importManager ): void {
        $this->importManager = $importManager;
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );

        // Advanced tab action handlers.
        add_action( 'admin_post_mmp_flush_cache',    [ $this, 'handleFlushCache' ] );
        add_action( 'admin_post_mmp_reset_settings', [ $this, 'handleResetSettings' ] );
        add_action( 'admin_post_mmp_delete_data',    [ $this, 'handleDeleteData' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function addMenuPage(): void {
        // Place under Media (upload.php), not Settings.
        add_submenu_page(
            'upload.php',
            __( 'MMP Settings', 'media-management-platform'),
            __( 'MMP Settings', 'media-management-platform'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // -------------------------------------------------------------------------
    // Settings API registration
    // -------------------------------------------------------------------------

    public function registerSettings(): void {
        register_setting(
            self::PAGE_SLUG,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitizeSettings' ],
                'default'           => $this->defaults(),
            ]
        );

        // --- General section -------------------------------------------------
        add_settings_section( self::SECTION_GEN, '', '__return_false', self::PAGE_SLUG . '-general' );

        add_settings_field( 'mmp_folder_mode',   __( 'Folder mode', 'media-management-platform'),    [ $this, 'renderFolderModeField' ],   self::PAGE_SLUG . '-general', self::SECTION_GEN );
        add_settings_field( 'mmp_default_sort',  __( 'Default sort', 'media-management-platform'),   [ $this, 'renderDefaultSortField' ],  self::PAGE_SLUG . '-general', self::SECTION_GEN );

        // --- Upload section --------------------------------------------------
        add_settings_section( self::SECTION_UPL, '', '__return_false', self::PAGE_SLUG . '-upload' );

        add_settings_field( 'mmp_auto_assign_folder', __( 'Auto-assign folder', 'media-management-platform'), [ $this, 'renderAutoAssignField' ], self::PAGE_SLUG . '-upload', self::SECTION_UPL );
        add_settings_field( 'mmp_allow_svg',          __( 'SVG uploads', 'media-management-platform'),         [ $this, 'renderAllowSvgField' ],    self::PAGE_SLUG . '-upload', self::SECTION_UPL );

        // --- Post Types section ----------------------------------------------
        add_settings_section( self::SECTION_CPT, '', '__return_false', self::PAGE_SLUG . '-post-types' );

        add_settings_field( 'mmp_enabled_post_types', __( 'Enabled post types', 'media-management-platform'), [ $this, 'renderPostTypesField' ], self::PAGE_SLUG . '-post-types', self::SECTION_CPT );
    }

    // -------------------------------------------------------------------------
    // Field renderers — General
    // -------------------------------------------------------------------------

    public function renderFolderModeField(): void {
        $current = $this->getSettings()['folder_mode'];
        $options = [
            'global'   => [ 'label' => __( 'Global (shared)', 'media-management-platform'),   'desc' => __( 'All users share one folder tree.', 'media-management-platform') ],
            'per_user' => [ 'label' => __( 'Per-user (private)', 'media-management-platform'), 'desc' => __( 'Each user has their own independent folder tree.', 'media-management-platform') ],
        ];

        foreach ( $options as $value => $opt ) {
            $id = 'mmp_folder_mode_' . $value;
            ?>
            <label for="<?php echo esc_attr( $id ); ?>" style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;cursor:pointer;">
                <input type="radio" id="<?php echo esc_attr( $id ); ?>"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[folder_mode]"
                    value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> style="margin-top:3px;">
                <span><strong><?php echo esc_html( $opt['label'] ); ?></strong><br>
                <span class="description"><?php echo esc_html( $opt['desc'] ); ?></span></span>
            </label>
            <?php
        }
    }

    public function renderDefaultSortField(): void {
        $current = $this->getSettings()['default_sort'];
        $options = [
            'name'  => __( 'Name (A–Z)', 'media-management-platform'),
            'date'  => __( 'Date (newest first)', 'media-management-platform'),
            'size'  => __( 'File size (largest first)', 'media-management-platform'),
            'type'  => __( 'File type', 'media-management-platform'),
        ];
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_sort]" id="mmp_default_sort">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Default sort order applied when a user first opens a folder.', 'media-management-platform'); ?></p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Field renderers — Upload
    // -------------------------------------------------------------------------

    public function renderAutoAssignField(): void {
        $settings = $this->getSettings();
        $checked  = (bool) ( $settings['auto_assign_folder'] ?? false );
        ?>
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
            <input type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_assign_folder]"
                id="mmp_auto_assign_folder" value="1" <?php checked( $checked ); ?> style="margin-top:3px;">
            <span>
                <strong><?php esc_html_e( 'Enable auto-assign', 'media-management-platform'); ?></strong><br>
                <span class="description"><?php esc_html_e( 'When enabled, files uploaded while a folder is active are automatically placed in that folder.', 'media-management-platform'); ?></span>
            </span>
        </label>
        <?php
    }

    public function renderAllowSvgField(): void {
        $settings = $this->getSettings();
        $checked  = (bool) ( $settings['allow_svg'] ?? false );
        ?>
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
            <input type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allow_svg]"
                id="mmp_allow_svg" value="1" <?php checked( $checked ); ?> style="margin-top:3px;">
            <span>
                <strong><?php esc_html_e( 'Allow SVG uploads', 'media-management-platform'); ?></strong><br>
                <span class="description"><?php esc_html_e( 'Adds SVG to the list of allowed upload MIME types. Ensure you trust all uploading users before enabling this.', 'media-management-platform'); ?></span>
            </span>
        </label>
        <?php
    }

    // -------------------------------------------------------------------------
    // Field renderers — Post Types
    // -------------------------------------------------------------------------

    public function renderPostTypesField(): void {
        $settings     = $this->getSettings();
        $enabledTypes = (array) ( $settings['enabled_post_types'] ?? [] );
        $available    = $this->getAvailablePostTypes();

        if ( empty( $available ) ) {
            echo '<p class="description">' . esc_html__( 'No public post types found.', 'media-management-platform') . '</p>';
            return;
        }

        foreach ( $available as $slug => $label ) {
            $id      = 'mmp_post_type_' . $slug;
            $checked = in_array( $slug, $enabledTypes, true );
            ?>
            <label for="<?php echo esc_attr( $id ); ?>" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;">
                <input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
                    name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled_post_types][]"
                    value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>>
                <span>
                    <strong><?php echo esc_html( $label ); ?></strong>
                    <code style="margin-left:6px;font-size:11px;color:#666;"><?php echo esc_html( $slug ); ?></code>
                </span>
            </label>
            <?php
        }

        echo '<p class="description" style="margin-top:8px;">'
            . esc_html__( 'Developers can also register types via the mmp_post_type_folders filter.', 'media-management-platform')
            . '</p>';
    }

    // -------------------------------------------------------------------------
    // Sanitisation
    // -------------------------------------------------------------------------

    /** @param mixed $input */
    public function sanitizeSettings( mixed $input ): array {
        $defaults = $this->defaults();

        if ( ! is_array( $input ) ) {
            return $defaults;
        }

        $clean = $defaults;

        // folder_mode
        $clean['folder_mode'] = in_array( $input['folder_mode'] ?? '', [ 'global', 'per_user' ], true )
            ? $input['folder_mode']
            : 'global';

        // default_sort
        $clean['default_sort'] = in_array( $input['default_sort'] ?? '', [ 'name', 'date', 'size', 'type' ], true )
            ? $input['default_sort']
            : 'date';

        // auto_assign_folder (checkbox — present = true, absent = false)
        $clean['auto_assign_folder'] = ! empty( $input['auto_assign_folder'] );

        // allow_svg (checkbox)
        $clean['allow_svg'] = ! empty( $input['allow_svg'] );

        // enabled_post_types
        $available = array_keys( $this->getAvailablePostTypes() );
        $submitted = is_array( $input['enabled_post_types'] ?? null ) ? $input['enabled_post_types'] : [];

        $clean['enabled_post_types'] = array_values(
            array_filter(
                array_map( 'sanitize_key', $submitted ),
                static fn( string $slug ) => in_array( $slug, $available, true )
            )
        );

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Advanced tab — action handlers
    // -------------------------------------------------------------------------

    public function handleFlushCache(): void {
        $this->checkPermission();
        $this->verifyAdvancedNonce( 'mmp_flush_cache' );

        // Delete all mmp_tree_* transients.
        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mmp_tree_%' OR option_name LIKE '_transient_timeout_mmp_tree_%'"
        );

        $this->redirectAdvanced( __( 'Folder tree cache flushed.', 'media-management-platform'), 'updated' );
    }

    public function handleResetSettings(): void {
        $this->checkPermission();
        $this->verifyAdvancedNonce( 'mmp_reset_settings' );

        update_option( self::OPTION_NAME, $this->defaults() );

        $this->redirectAdvanced( __( 'Settings reset to defaults.', 'media-management-platform'), 'updated' );
    }

    public function handleDeleteData(): void {
        $this->checkPermission();
        $this->verifyAdvancedNonce( 'mmp_delete_data' );

        // Confirm token must be present to prevent accidental deletes.
        $confirm = sanitize_text_field( wp_unslash( $_POST['mmp_delete_confirm'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by verifyAdvancedNonce() above
        if ( 'DELETE' !== $confirm ) {
            $this->redirectAdvanced( __( 'Deletion cancelled — you must type DELETE to confirm.', 'media-management-platform'), 'error' );
        }

        // Remove plugin option.
        delete_option( self::OPTION_NAME );

        // Remove all mmp_folder taxonomy terms and their meta.
        $terms = get_terms( [ 'taxonomy' => \MMP\Taxonomy\FolderTaxonomy::TAXONOMY, 'hide_empty' => false, 'number' => 0, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_number -- intentional: must fetch all folder terms to delete them
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $termId ) {
                wp_delete_term( (int) $termId, \MMP\Taxonomy\FolderTaxonomy::TAXONOMY );
            }
        }

        // Drop custom tables.
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'mmp_user_prefs',
            $wpdb->prefix . 'mmp_versions',
            $wpdb->prefix . 'mmp_usage',
            $wpdb->prefix . 'mmp_permissions',
            $wpdb->prefix . 'mmp_analytics',
            $wpdb->prefix . 'mmp_tags',
            $wpdb->prefix . 'mmp_tag_relationships',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        // Flush transients.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mmp_%' OR option_name LIKE '_transient_timeout_mmp_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        // Remove migration progress options.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mmp_migration_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        $this->redirectAdvanced( __( 'All MMP data deleted.', 'media-management-platform'), 'updated' );
    }

    // -------------------------------------------------------------------------
    // Page renderer
    // -------------------------------------------------------------------------

    public function renderPage(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'media-management-platform') );
        }

        $requestedTab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $activeTab    = in_array( $requestedTab, self::VALID_TABS, true ) ? $requestedTab : self::TAB_GENERAL;

        $this->renderNotice();
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:16px;">
                <?php esc_html_e( 'MMP Settings', 'media-management-platform'); ?>
                <a href="https://www.paypal.com/donate/?hosted_button_id=brainstudioz"
                   target="_blank"
                   rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#0070ba;color:#fff;border-radius:4px;text-decoration:none;font-size:13px;font-weight:600;line-height:1.4;">
                    &#9829; <?php esc_html_e( 'Donate — Support Development', 'media-management-platform'); ?>
                </a>
            </h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <?php
                $tabs = [
                    self::TAB_GENERAL    => __( 'General', 'media-management-platform'),
                    self::TAB_UPLOAD     => __( 'Upload', 'media-management-platform'),
                    self::TAB_POST_TYPES => __( 'Post Types', 'media-management-platform'),
                    self::TAB_ADVANCED   => __( 'Advanced', 'media-management-platform'),
                    self::TAB_CSV        => __( 'Import / Export', 'media-management-platform'),
                    self::TAB_MIGRATION  => __( 'Migrate from Plugin', 'media-management-platform'),
                ];
                foreach ( $tabs as $slug => $label ) :
                    $url = admin_url( 'upload.php?page=' . self::PAGE_SLUG . '&tab=' . $slug );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="nav-tab <?php echo $activeTab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            match ( $activeTab ) {
                self::TAB_GENERAL    => $this->renderSettingsForm( self::PAGE_SLUG . '-general' ),
                self::TAB_UPLOAD     => $this->renderSettingsForm( self::PAGE_SLUG . '-upload' ),
                self::TAB_POST_TYPES => $this->renderSettingsForm( self::PAGE_SLUG . '-post-types' ),
                self::TAB_ADVANCED   => $this->renderAdvancedTab(),
                self::TAB_CSV        => $this->renderCsvTab(),
                self::TAB_MIGRATION  => $this->renderMigrationTab(),
            };
            ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab sub-renderers — Settings form wrapper
    // -------------------------------------------------------------------------

    private function renderSettingsForm( string $sectionPage ): void {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( self::PAGE_SLUG );
            do_settings_sections( $sectionPage );
            submit_button();
            ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Advanced tab
    // -------------------------------------------------------------------------

    private function renderAdvancedTab(): void {
        ?>
        <h2><?php esc_html_e( 'Cache', 'media-management-platform'); ?></h2>
        <p class="description"><?php esc_html_e( 'MMP caches the folder tree in WordPress transients. Flush the cache if folders appear out of date.', 'media-management-platform'); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mmp_flush_cache', 'mmp_advanced_nonce' ); ?>
            <input type="hidden" name="action" value="mmp_flush_cache">
            <?php submit_button( __( 'Flush folder tree cache', 'media-management-platform'), 'secondary', 'submit', false ); ?>
        </form>

        <hr>

        <h2><?php esc_html_e( 'Reset settings', 'media-management-platform'); ?></h2>
        <p class="description"><?php esc_html_e( 'Restores all plugin settings to their default values. Folder data is not affected.', 'media-management-platform'); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mmp_reset_settings', 'mmp_advanced_nonce' ); ?>
            <input type="hidden" name="action" value="mmp_reset_settings">
            <?php submit_button( __( 'Reset to defaults', 'media-management-platform'), 'secondary', 'submit', false ); ?>
        </form>

        <hr>

        <h2 style="color:#b32d2e;"><?php esc_html_e( 'Delete all plugin data', 'media-management-platform'); ?></h2>
        <p class="description" style="color:#b32d2e;">
            <?php esc_html_e( 'Permanently deletes all MMP folders, file assignments, custom tables, and settings. This cannot be undone.', 'media-management-platform'); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mmp_delete_data', 'mmp_advanced_nonce' ); ?>
            <input type="hidden" name="action" value="mmp_delete_data">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="mmp_delete_confirm"><?php esc_html_e( 'Confirm deletion', 'media-management-platform'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mmp_delete_confirm" name="mmp_delete_confirm"
                            placeholder="<?php esc_attr_e( 'Type DELETE to confirm', 'media-management-platform'); ?>"
                            style="width:220px;">
                        <p class="description"><?php esc_html_e( 'Type DELETE (in caps) and click the button to proceed.', 'media-management-platform'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Delete all MMP data', 'media-management-platform'), 'delete', 'submit', false ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // CSV tab
    // -------------------------------------------------------------------------

    private function renderCsvTab(): void {
        $exportFoldersUrl = wp_nonce_url(
            admin_url( 'admin-post.php?action=mmp_export_folders' ),
            'mmp_export_folders',
            'mmp_csv_export'
        );
        $exportAssignmentsUrl = wp_nonce_url(
            admin_url( 'admin-post.php?action=mmp_export_assignments' ),
            'mmp_export_assignments',
            'mmp_csv_export'
        );
        ?>
        <h2><?php esc_html_e( 'Export', 'media-management-platform'); ?></h2>
        <p class="description"><?php esc_html_e( 'Download your folder data as CSV files for backup or migration.', 'media-management-platform'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Folder structure', 'media-management-platform'); ?></th>
                <td>
                    <a href="<?php echo esc_url( $exportFoldersUrl ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Download folders.csv', 'media-management-platform'); ?>
                    </a>
                    <p class="description"><?php esc_html_e( 'Exports all folders: id, name, parent_id, color, file_count.', 'media-management-platform'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'File assignments', 'media-management-platform'); ?></th>
                <td>
                    <a href="<?php echo esc_url( $exportAssignmentsUrl ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Download file-assignments.csv', 'media-management-platform'); ?>
                    </a>
                    <p class="description"><?php esc_html_e( 'Exports all file-to-folder assignments: attachment_id, folder_id, folder_name, file_title, file_url.', 'media-management-platform'); ?></p>
                </td>
            </tr>
        </table>

        <hr>

        <h2><?php esc_html_e( 'Import', 'media-management-platform'); ?></h2>
        <p class="description"><?php esc_html_e( 'Upload a previously exported CSV to recreate your folder tree or restore file assignments.', 'media-management-platform'); ?></p>

        <h3><?php esc_html_e( 'Folder structure', 'media-management-platform'); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'mmp_import_folders', 'mmp_csv_import' ); ?>
            <input type="hidden" name="action" value="mmp_import_folders">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mmp_csv_folders_file"><?php esc_html_e( 'Upload folders CSV', 'media-management-platform'); ?></label></th>
                    <td>
                        <input type="file" id="mmp_csv_folders_file" name="mmp_csv_file" accept=".csv,text/csv">
                        <p class="description"><?php esc_html_e( 'Recreates the folder tree from a folders.csv export. Existing folders are not removed.', 'media-management-platform'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Import folder structure', 'media-management-platform'), 'secondary' ); ?>
        </form>

        <h3><?php esc_html_e( 'File assignments', 'media-management-platform'); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'mmp_import_assignments', 'mmp_csv_import' ); ?>
            <input type="hidden" name="action" value="mmp_import_assignments">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="mmp_csv_assignments_file"><?php esc_html_e( 'Upload assignments CSV', 'media-management-platform'); ?></label></th>
                    <td>
                        <input type="file" id="mmp_csv_assignments_file" name="mmp_csv_file" accept=".csv,text/csv">
                        <p class="description"><?php esc_html_e( 'Reassigns files to folders from a file-assignments.csv export.', 'media-management-platform'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Restore file assignments', 'media-management-platform'), 'secondary' ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Migration tab
    // -------------------------------------------------------------------------

    private function renderMigrationTab(): void {
        if ( null === $this->importManager ) {
            echo '<p>' . esc_html__( 'Migration service unavailable.', 'media-management-platform') . '</p>';
            return;
        }

        $importers = $this->importManager->getImporters();
        ?>
        <h2><?php esc_html_e( 'Migrate from another plugin', 'media-management-platform'); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Import your existing folder structure and file assignments from another media organiser plugin. The import runs in the background using WP-Cron and will not time out on large libraries.', 'media-management-platform'); ?>
        </p>

        <?php if ( empty( $importers ) ) : ?>
            <p><?php esc_html_e( 'No importers registered.', 'media-management-platform'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:800px;margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Plugin', 'media-management-platform'); ?></th>
                        <th><?php esc_html_e( 'Source taxonomy', 'media-management-platform'); ?></th>
                        <th><?php esc_html_e( 'Status', 'media-management-platform'); ?></th>
                        <th><?php esc_html_e( 'Progress', 'media-management-platform'); ?></th>
                        <th><?php esc_html_e( 'Actions', 'media-management-platform'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $runningSlug = '';
                foreach ( $importers as $slug => $importer ) :
                    $available = $importer->isAvailable();
                    $progress  = $this->importManager->getProgress( $slug );
                    $isRunning = 'running' === $progress->status;
                    $isDone    = 'done'    === $progress->status;

                    if ( $isRunning ) {
                        $runningSlug = $slug;
                    }
                ?>
                    <tr id="mmp-migration-row-<?php echo esc_attr( $slug ); ?>">
                        <td><strong><?php echo esc_html( $importer->getLabel() ); ?></strong></td>
                        <td><code><?php echo esc_html( $importer->getSourceTaxonomy() ); ?></code></td>
                        <td>
                            <?php if ( ! $available ) : ?>
                                <span style="color:#999;"><?php esc_html_e( 'Plugin not active', 'media-management-platform'); ?></span>
                            <?php elseif ( $isRunning ) : ?>
                                <span style="color:#0073aa;" class="mmp-migration-status" data-slug="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Running…', 'media-management-platform'); ?></span>
                            <?php elseif ( $isDone ) : ?>
                                <span style="color:#46b450;"><?php esc_html_e( 'Done', 'media-management-platform'); ?></span>
                            <?php elseif ( 'error' === $progress->status ) : ?>
                                <span style="color:#dc3232;"><?php esc_html_e( 'Error', 'media-management-platform'); ?></span>
                            <?php else : ?>
                                <span style="color:#999;"><?php esc_html_e( 'Idle', 'media-management-platform'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $progress->total > 0 || $isDone ) : ?>
                                <div style="background:#ddd;border-radius:3px;height:12px;width:160px;overflow:hidden;">
                                    <div class="mmp-migration-bar" data-slug="<?php echo esc_attr( $slug ); ?>"
                                         style="background:#0073aa;height:100%;width:<?php echo esc_attr( (string) $progress->percent() ); ?>%;transition:width .4s;"></div>
                                </div>
                                <span class="mmp-migration-pct" data-slug="<?php echo esc_attr( $slug ); ?>" style="font-size:11px;color:#666;">
                                    <?php echo esc_html( $progress->percent() . '% (' . $progress->processed . '/' . $progress->total . ')' ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $available && ! $isRunning ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'mmp_migration_' . $slug, 'mmp_migration_nonce' ); ?>
                                    <input type="hidden" name="action" value="mmp_migration_start">
                                    <input type="hidden" name="mmp_importer_slug" value="<?php echo esc_attr( $slug ); ?>">
                                    <button type="submit" class="button button-primary">
                                        <?php echo $isDone ? esc_html__( 'Re-import', 'media-management-platform') : esc_html__( 'Start import', 'media-management-platform'); ?>
                                    </button>
                                </form>
                            <?php elseif ( $isRunning ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'mmp_migration_' . $slug, 'mmp_migration_nonce' ); ?>
                                    <input type="hidden" name="action" value="mmp_migration_cancel">
                                    <input type="hidden" name="mmp_importer_slug" value="<?php echo esc_attr( $slug ); ?>">
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Cancel', 'media-management-platform'); ?></button>
                                </form>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( ! empty( $progress->messages ) ) : ?>
                    <tr>
                        <td colspan="5">
                            <details>
                                <summary style="cursor:pointer;font-size:12px;color:#666;"><?php esc_html_e( 'Show log', 'media-management-platform'); ?></summary>
                                <pre style="font-size:11px;max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;margin:4px 0;"><?php echo esc_html( implode( "\n", $progress->messages ) ); ?></pre>
                            </details>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( '' !== $runningSlug ) : ?>
            <script>
            (function () {
                'use strict';
                var slug     = <?php echo json_encode( $runningSlug ); ?>;
                var restBase = <?php echo json_encode( esc_url_raw( rest_url( 'mmp/v1/migration/progress' ) ) ); ?>;
                var nonce    = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

                function poll() {
                    fetch( restBase + '?slug=' + encodeURIComponent( slug ), { headers: { 'X-WP-Nonce': nonce } } )
                        .then( function(r) { return r.json(); } )
                        .then( function(data) {
                            var pct   = data.total > 0 ? Math.round( (data.processed / data.total) * 100 ) : (data.status === 'done' ? 100 : 0);
                            var bar   = document.querySelector( '.mmp-migration-bar[data-slug="' + slug + '"]' );
                            var pctEl = document.querySelector( '.mmp-migration-pct[data-slug="' + slug + '"]' );
                            var stat  = document.querySelector( '.mmp-migration-status[data-slug="' + slug + '"]' );
                            if ( bar )   bar.style.width = pct + '%';
                            if ( pctEl ) pctEl.textContent = pct + '% (' + data.processed + '/' + data.total + ')';
                            if ( data.status !== 'running' ) {
                                if ( stat ) stat.textContent = data.status === 'done' ? '<?php esc_html_e( 'Done', 'media-management-platform'); ?>' : '<?php esc_html_e( 'Error', 'media-management-platform'); ?>';
                                setTimeout( function () { window.location.reload(); }, 1500 );
                            } else {
                                setTimeout( poll, 5000 );
                            }
                        } )
                        .catch( function() { setTimeout( poll, 10000 ); } );
                }
                setTimeout( poll, 5000 );
            }());
            </script>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function renderNotice(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin redirect notice, no state change
        $notice  = isset( $_GET['mmp_notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mmp_notice'] ) ) : '';
        if ( empty( $notice ) ) {
            return;
        }
        $rawStatus = isset( $_GET['mmp_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['mmp_status'] ) ) : '';
        $status  = in_array( $rawStatus, [ 'error', 'updated', 'info' ], true ) ? $rawStatus : 'updated';
        $message = sanitize_text_field( urldecode( $notice ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $status ),
            esc_html( $message )
        );
    }

    private function checkPermission(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'media-management-platform') );
        }
    }

    private function verifyAdvancedNonce( string $action ): void {
        $nonce = sanitize_text_field( wp_unslash( (string) ( $_POST['mmp_advanced_nonce'] ?? '' ) ) );
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'media-management-platform') );
        }
    }

    private function redirectAdvanced( string $message, string $status = 'updated' ): never {
        $url = add_query_arg(
            [ 'mmp_notice' => rawurlencode( $message ), 'mmp_status' => $status ],
            admin_url( 'upload.php?page=' . self::PAGE_SLUG . '&tab=' . self::TAB_ADVANCED )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /** @return array<string, string> slug => label */
    private function getAvailablePostTypes(): array {
        $objects = get_post_types( [ 'public' => true ], 'objects' );
        $result  = [];

        foreach ( $objects as $slug => $obj ) {
            if ( in_array( $slug, self::EXCLUDED_POST_TYPES, true ) ) {
                continue;
            }
            $result[ $slug ] = $obj->labels->name ?? $slug;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function defaults(): array {
        return [
            'folder_mode'         => 'global',
            'default_sort'        => 'date',
            'auto_assign_folder'  => false,
            'allow_svg'           => false,
            'enabled_post_types'  => [],
        ];
    }

    /** @return array<string, mixed> */
    private function getSettings(): array {
        $saved = get_option( self::OPTION_NAME, [] );
        return array_merge( $this->defaults(), is_array( $saved ) ? $saved : [] );
    }
}
