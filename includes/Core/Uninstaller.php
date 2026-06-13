<?php

declare(strict_types=1);

namespace MMP\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Cleans up all plugin data when the user deletes the plugin
 * via the WordPress admin Plugins screen.
 *
 * Called from uninstall.php (WordPress invokes that file directly,
 * bypassing the normal plugin boot sequence).
 */
final class Uninstaller {

    public static function uninstall(): void {
        // Guard: only run when WP triggers uninstall.
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }

        self::dropTables();
        self::deleteOptions();
        self::deletePostMeta();
        self::deleteUserMeta();
        self::deleteTermMeta();
        self::removeTaxonomy();
        self::removeCapabilities();
        self::clearScheduledEvents();
    }

    // -------------------------------------------------------------------------

    private static function dropTables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'mmp_tag_relationships',
            $wpdb->prefix . 'mmp_tags',
            $wpdb->prefix . 'mmp_analytics',
            $wpdb->prefix . 'mmp_permissions',
            $wpdb->prefix . 'mmp_usage',
            $wpdb->prefix . 'mmp_versions',
            $wpdb->prefix . 'mmp_user_prefs',
            $wpdb->prefix . 'mmp_share_links',
            $wpdb->prefix . 'mmp_share_downloads',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        }
    }

    private static function deleteOptions(): void {
        $options = [
            'mmp_settings',
            'mmp_db_version',
            'mmp_ai_settings',          // Contains Google Vision / AWS API credentials.
            'mmp_optimization_settings',
            'mmp_filesystem_settings',
            'mmp_folder_templates',
            'mmp_analytics_queue',
            'mmp_portal_rules_flushed',
            'mmp_doclib_public_roots',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Remove dynamically named migration ID maps and all mmp transients.
        global $wpdb;
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'mmp\_migration\_%'
                OR option_name LIKE '_transient_mmp_%'
                OR option_name LIKE '_transient_timeout_mmp_%'"
        );
    }

    private static function deletePostMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->postmeta}
             WHERE meta_key LIKE 'mmp_%'"
        );
    }

    private static function deleteUserMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->usermeta}
             WHERE meta_key LIKE 'mmp\_%'"
        );
    }

    private static function deleteTermMeta(): void {
        global $wpdb;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->termmeta}
             WHERE meta_key LIKE 'mmp_%'"
        );
    }

    /**
     * Remove all terms and the taxonomy itself.
     * We delete terms assigned to the mmp_folder taxonomy so no orphan rows
     * remain in wp_terms / wp_term_taxonomy / wp_term_relationships.
     */
    private static function removeTaxonomy(): void {
        $terms = get_terms( [
            'taxonomy'   => 'mmp_folder',
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        foreach ( $terms as $termId ) {
            wp_delete_term( (int) $termId, 'mmp_folder' );
        }
    }

    private static function removeCapabilities(): void {
        $roles = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
        $caps  = [ 'manage_mmp_folders', 'manage_mmp_settings' ];

        foreach ( $roles as $roleName ) {
            $role = get_role( $roleName );

            if ( ! $role ) {
                continue;
            }

            foreach ( $caps as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    private static function clearScheduledEvents(): void {
        $hooks = [
            'mmp_import_batch',
            'mmp_duplicate_scan',
            'mmp_cloud_sync',
            'mmp_backup_run',
            'mmp_usage_scan',
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );

            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
        }
    }
}
