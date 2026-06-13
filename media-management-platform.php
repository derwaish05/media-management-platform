<?php
/**
 * Plugin Name:       Media Management Platform
 * Plugin URI:        https://brainstudioz.com/media-management-platform
 * Description:       Media OS for WordPress — full media lifecycle from upload to delivery to analytics.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            BrainStudioz
 * Author URI:        https://brainstudioz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-management-platform
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'MMP_VERSION',    '1.0.0' );
define( 'MMP_DB_VERSION', '1.1.0' );
define( 'MMP_PATH',       plugin_dir_path( __FILE__ ) );
define( 'MMP_URL',        plugin_dir_url( __FILE__ ) );
define( 'MMP_BASENAME',   plugin_basename( __FILE__ ) );
define( 'MMP_FILE',       __FILE__ );

// Autoloader.
if ( file_exists( MMP_PATH . 'vendor/autoload.php' ) ) {
    require_once MMP_PATH . 'vendor/autoload.php';
} else {
    // Fallback manual autoloader (used before `composer install`).
    spl_autoload_register( function ( string $class ): void {
        $prefix = 'MMP\\';
        $base   = MMP_PATH . 'includes/';

        if ( ! str_starts_with( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $file     = $base . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    } );
}

// Activation hook.
register_activation_hook( MMP_FILE, function (): void {
    MMP\Core\Installer::install();
} );

// Deactivation hook.
register_deactivation_hook( MMP_FILE, function (): void {
    flush_rewrite_rules();
} );

// Uninstall is handled via uninstall.php (WP calls it directly).

// Boot the plugin after all plugins are loaded.
add_action( 'plugins_loaded', function (): void {
    MMP\Core\Plugin::getInstance()->boot();
} );
