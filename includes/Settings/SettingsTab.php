<?php

namespace MediaStorage\JetFormBuilder\Settings;

use MediaStorage\JetFormBuilder\Plugin;
use Jet_Form_Builder\Admin\Tabs_Handlers\Base_Handler;
use Jet_Form_Builder\Admin\Pages\Pages_Manager;
use function rest_sanitize_boolean;
use function sanitize_text_field;
use function rest_url;
use function str_replace;
use function wp_unslash;

class SettingsTab extends Base_Handler {

	public function slug() {
		return 'media-storage-settings-tab';
	}

	public function before_assets() {
		$handle = Plugin::instance()->slug() . '-' . $this->slug();

		wp_enqueue_style( Pages_Manager::STYLE_ADMIN );
		wp_enqueue_script( Pages_Manager::SCRIPT_VUEX_PACKAGE );
		wp_enqueue_script( Pages_Manager::SCRIPT_PACKAGE );

		$script_path = Plugin::instance()->path( 'assets/js/settings-tab.js' );
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : Plugin::instance()->version();
		$style_path  = Plugin::instance()->path( 'assets/css/settings-tab.css' );
		$style_ver   = file_exists( $style_path ) ? (string) filemtime( $style_path ) : Plugin::instance()->version();

		wp_deregister_script( $handle );

		wp_register_script(
			$handle,
			Plugin::instance()->url( 'assets/js/settings-tab.js' ),
			array(
				Pages_Manager::SCRIPT_VUEX_PACKAGE,
				'wp-hooks',
				'wp-i18n',
				'wp-api-fetch',
			),
			$version,
			true
		);

		wp_enqueue_script( $handle );
		wp_enqueue_style(
			$handle . '-styles',
			Plugin::instance()->url( 'assets/css/settings-tab.css' ),
			array( Pages_Manager::STYLE_ADMIN ),
			$style_ver
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				$handle,
				'media-storage-for-jetformbuilder'
			);
		}

		wp_localize_script(
			$handle,
			'MSJFBSettingsMeta',
			array(
				'dropbox' => array(
					'callback_url'   => rest_url( 'msjfb/v1/dropbox/callback' ),
					'authorize_path' => '/msjfb/v1/dropbox/authorize',
				),
				'gdrive' => array(
					'callback_url'   => rest_url( 'msjfb/v1/gdrive/callback' ),
					'authorize_path' => '/msjfb/v1/gdrive/authorize',
				),
				'file_type_options' => self::get_file_type_options(),
			)
		);
	}

	public function on_get_request() {
		$payload = array(
			'delete_original' => rest_sanitize_boolean(
				wp_unslash( $_POST['delete_original'] ?? false )
			),
			'folder_template' => $this->sanitize_template( $_POST['folder_template'] ?? SettingsRepository::default_folder_template() ),
			'max_filesize_mb'    => $this->sanitize_filesize( $_POST['max_filesize_mb'] ?? 0 ),
			'allowed_file_types' => $this->sanitize_allowed_file_types( $_POST['allowed_file_types'] ?? array() ),
			'debug_enabled'      => rest_sanitize_boolean( wp_unslash( $_POST['debug_enabled'] ?? false ) ),
			'providers'       => $this->sanitize_providers( $_POST['providers'] ?? array() ),
		);

		$result = $this->update_options( $payload );

		$this->send_response( $result );
	}

	public function on_load() {
		return $this->get_options( SettingsRepository::defaults() );
	}

	private function sanitize_providers( $providers ): array {
		$providers = is_array( $providers ) ? $providers : array();
		$sanitized = SettingsRepository::provider_defaults();
		$stored    = SettingsRepository::get()['providers'] ?? array();

		$credential_keys = array(
			'dropbox' => array( 'app_key', 'app_secret' ),
			'gdrive'  => array( 'client_id', 'client_secret' ),
		);

		foreach ( $sanitized as $key => $defaults ) {
			$current = isset( $providers[ $key ] ) && is_array( $providers[ $key ] )
				? $providers[ $key ]
				: array();

			$sanitized[ $key ]['enabled'] = rest_sanitize_boolean(
				wp_unslash( $current['enabled'] ?? false )
			);

			foreach ( $defaults as $field => $default_value ) {
				if ( 'enabled' === $field ) {
					continue;
				}

				$sanitized[ $key ][ $field ] = sanitize_text_field(
					wp_unslash( $current[ $field ] ?? '' )
				);
			}

			if ( isset( $credential_keys[ $key ] ) ) {
				$old = $stored[ $key ] ?? array();
				foreach ( $credential_keys[ $key ] as $cred_field ) {
					if ( ( $old[ $cred_field ] ?? '' ) !== $sanitized[ $key ][ $cred_field ] ) {
						$sanitized[ $key ]['access_token']            = '';
						$sanitized[ $key ]['access_token_expires_at'] = '';
						break;
					}
				}
			}
		}

		return $sanitized;
	}

	private function sanitize_template( $template ): string {
		$template = is_string( $template ) ? $template : '';
		$template = trim( wp_unslash( $template ) );

		if ( '' === $template ) {
			return SettingsRepository::default_folder_template();
		}

		return $template;
	}

	private function sanitize_filesize( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( wp_unslash( $value ) ) );
		}

		if ( null === $value || '' === $value ) {
			return 0.0;
		}

		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}

		$number = (float) $value;

		if ( $number < 0 ) {
			$number = -1;
		}

		return (float) round( $number, 4 );
	}

	private function sanitize_allowed_file_types( $types ): array {
		if ( ! is_array( $types ) ) {
			return array();
		}

		$valid_mimes = array_values( get_allowed_mime_types() );

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', $types ),
				static function ( $type ) use ( $valid_mimes ) {
					return in_array( $type, $valid_mimes, true );
				}
			)
		);
	}

	private static function get_file_type_options(): array {
		$mimes  = get_allowed_mime_types();
		$groups = array();

		foreach ( $mimes as $extensions => $mime ) {
			$category = explode( '/', $mime, 2 )[0];
			$label    = str_replace( '|', ', ', $extensions );

			if ( ! isset( $groups[ $category ] ) ) {
				$groups[ $category ] = array();
			}

			$groups[ $category ][] = array(
				'value' => $mime,
				'label' => $label,
			);
		}

		return $groups;
	}

	public function get_options( $if_empty = array() ) {
		$options = parent::get_options( $if_empty );

		$options['folder_template'] = isset( $options['folder_template'] ) && is_string( $options['folder_template'] )
			? $options['folder_template']
			: SettingsRepository::default_folder_template();

		$options['max_filesize_mb'] = isset( $options['max_filesize_mb'] )
			? $this->sanitize_filesize( $options['max_filesize_mb'] )
			: 0.0;

		$options['allowed_file_types'] = isset( $options['allowed_file_types'] ) && is_array( $options['allowed_file_types'] )
			? array_values( array_filter( $options['allowed_file_types'], 'is_string' ) )
			: array();

		$options['debug_enabled'] = isset( $options['debug_enabled'] )
			? (bool) $options['debug_enabled']
			: false;

		return $options;
	}
}
