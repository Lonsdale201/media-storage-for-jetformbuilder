<?php
/**
 * Plugin Name: Media Storage for JetFormBuilder
 * Description: Configure external storage providers for JetFormBuilder uploads.
 * Author:      Soczó Kristóf
 * Author URI:  https://github.com/Lonsdale201?tab=repositories
 * Plugin URI:  https://github.com/Lonsdale201/media-storage-for-jetformbuilder
 * Version:     1.0
 * Text Domain: media-storage-for-jetformbuilder
 * Requires PHP: 8.0
 * Requires Plugins: jetformbuilder
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

use MediaStorage\JetFormBuilder\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSJFB_PLUGIN_FILE', __FILE__ );
define( 'MSJFB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MSJFB_PLUGIN_URL', plugins_url( '/', __FILE__ ) );
define( 'MSJFB_DISABLE_FORM_META', '_msjfb_disable_media_storage' );

const MSJFB_MIN_PHP_VERSION = '8.0';
const MSJFB_MIN_WP_VERSION  = '6.0';

$autoload = MSJFB_PLUGIN_PATH . 'vendor/autoload.php';
$update_checker_bootstrap = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $update_checker_bootstrap ) ) {
	require_once $update_checker_bootstrap;
}

if ( file_exists( $autoload ) ) {
	require $autoload;
}

if ( ! function_exists( 'msjfb_translate' ) ) {
	function msjfb_translate( string $text ): string {
		if ( did_action( 'init' ) ) {
			return __( $text, 'media-storage-for-jetformbuilder' );
		}

		return $text;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'MediaStorage\\JetFormBuilder\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', '/', $relative_class ) . '.php';
		$file           = MSJFB_PLUGIN_PATH . 'includes/' . $relative_path;

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'init',
	static function () {
		$domain = 'media-storage-for-jetformbuilder';
		$locale = determine_locale();
		$mofile = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';

		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
		}

		load_plugin_textdomain(
			$domain,
			false,
			dirname( plugin_basename( MSJFB_PLUGIN_FILE ) ) . '/languages'
		);
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		$errors = msjfb_requirement_errors();

		if ( empty( $errors ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( __FILE__ ) );
		unset( $_GET['activate'] );

		$GLOBALS['msjfb_activation_errors'] = $errors;

		add_action( 'admin_notices', 'msjfb_activation_admin_notice' );
	}
);

if ( ! function_exists( 'msjfb_requirement_errors' ) ) {
	function msjfb_requirement_errors( bool $include_plugin_checks = true ): array {
		$errors = array();

		if ( version_compare( PHP_VERSION, MSJFB_MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				msjfb_translate( 'Media Storage for JetFormBuilder requires PHP version %1$s or higher. Current version: %2$s.' ),
				MSJFB_MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		global $wp_version;

		if ( version_compare( $wp_version, MSJFB_MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				msjfb_translate( 'Media Storage for JetFormBuilder requires WordPress version %1$s or higher. Current version: %2$s.' ),
				MSJFB_MIN_WP_VERSION,
				$wp_version
			);
		}

		if ( ! $include_plugin_checks ) {
			return $errors;
		}

		if ( ! function_exists( 'jet_form_builder' ) && ! class_exists( '\Jet_Form_Builder\Plugin' ) ) {
			$errors[] = msjfb_translate( 'Media Storage for JetFormBuilder requires the JetFormBuilder plugin to be installed and active.' );
		}

		return $errors;
	}
}

if ( ! function_exists( 'msjfb_activation_admin_notice' ) ) {
	function msjfb_activation_admin_notice(): void {
		if ( empty( $GLOBALS['msjfb_activation_errors'] ) || ! is_array( $GLOBALS['msjfb_activation_errors'] ) ) {
			return;
		}

		$errors = $GLOBALS['msjfb_activation_errors'];

		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>%s</strong></p><ul><li>%s</li></ul></div>',
			esc_html( msjfb_translate( 'Media Storage for JetFormBuilder could not be activated.' ) ),
			implode( '</li><li>', array_map( 'esc_html', $errors ) )
		);

		unset( $GLOBALS['msjfb_activation_errors'] );
	}
}

if ( ! function_exists( 'msjfb_admin_notice' ) ) {
	function msjfb_admin_notice(): void {
		$errors = $GLOBALS['msjfb_runtime_errors'] ?? msjfb_requirement_errors();

		if ( empty( $errors ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><ul><li>%s</li></ul></div>',
			esc_html( msjfb_translate( 'Media Storage for JetFormBuilder cannot run:' ) ),
			implode( '</li><li>', array_map( 'esc_html', $errors ) )
		);
	}
}

$initial_environment_errors = msjfb_requirement_errors( false );

if ( ! empty( $initial_environment_errors ) ) {
	$GLOBALS['msjfb_runtime_errors'] = $initial_environment_errors;

	if ( is_admin() ) {
		add_action( 'admin_notices', 'msjfb_admin_notice' );
	}

	return;
}

add_action(
	'plugins_loaded',
	static function () {
		$errors = msjfb_requirement_errors();

		if ( ! empty( $errors ) ) {
			$GLOBALS['msjfb_runtime_errors'] = $errors;

			if ( is_admin() ) {
				add_action( 'admin_notices', 'msjfb_admin_notice' );
			}

			return;
		}

		Plugin::instance( MSJFB_PLUGIN_FILE );
	}
);
