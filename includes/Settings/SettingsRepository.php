<?php

namespace MediaStorage\JetFormBuilder\Settings;

use function json_decode;

class SettingsRepository {

	public const OPTION_NAME = 'jet_form_builder_settings__media-storage-settings-tab';

	public static function defaults(): array {
		return array(
			'delete_original' => false,
			'folder_template' => self::default_folder_template(),
			'max_filesize_mb'    => 0.0,
			'allowed_file_types' => array(),
			'debug_enabled'      => false,
			'providers'       => self::provider_defaults(),
		);
	}

	public static function default_folder_template(): string {
		return 'JetFormBuilder/%formname%/%currentdate%';
	}

	public static function provider_defaults(): array {
		return array(
			'dropbox'   => array(
				'enabled'                 => false,
				'access_token'            => '',
				'access_token_expires_at' => '',
				'refresh_token'           => '',
				'app_key'                 => '',
				'app_secret'              => '',
			),
			'cloudflare' => array(
				'enabled'    => false,
				'account_id' => '',
				'access_key' => '',
				'secret_key' => '',
				'bucket'     => '',
				'region'     => '',
			),
			'gdrive'    => array(
				'enabled'                 => false,
				'client_id'               => '',
				'client_secret'           => '',
				'refresh_token'           => '',
				'access_token'            => '',
				'access_token_expires_at' => '',
				'folder_id'               => '',
			),
		);
	}

	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, '' );

		if ( is_string( $stored ) && $stored ) {
			$data = json_decode( $stored, true );
		} else {
			$data = is_array( $stored ) ? $stored : array();
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$defaults = self::defaults();

		return array(
			'delete_original' => ! empty( $data['delete_original'] ),
			'folder_template' => isset( $data['folder_template'] ) && is_string( $data['folder_template'] )
				? $data['folder_template']
				: self::default_folder_template(),
			'max_filesize_mb'    => self::normalize_filesize_value( $data['max_filesize_mb'] ?? 0 ),
			'allowed_file_types' => isset( $data['allowed_file_types'] ) && is_array( $data['allowed_file_types'] )
				? array_values( array_filter( $data['allowed_file_types'], 'is_string' ) )
				: array(),
			'debug_enabled'      => ! empty( $data['debug_enabled'] ),
			'providers'       => self::merge_providers( $data['providers'] ?? array(), $defaults['providers'] ),
		);
	}

	public static function provider( string $name ): array {
		$settings = self::get();

		return $settings['providers'][ $name ] ?? ( self::provider_defaults()[ $name ] ?? array() );
	}

	public static function delete_original_enabled(): bool {
		$settings = self::get();

		return ! empty( $settings['delete_original'] );
	}

	public static function provider_enabled( string $name ): bool {
		$provider = self::provider( $name );

		return ! empty( $provider['enabled'] );
	}

	public static function update_provider_settings( string $name, array $patch ): void {
		if ( empty( $patch ) ) {
			return;
		}

		$data = self::get_raw_option();

		if ( ! isset( $data['providers'] ) || ! is_array( $data['providers'] ) ) {
			$data['providers'] = array();
		}

		$current = isset( $data['providers'][ $name ] ) && is_array( $data['providers'][ $name ] )
			? $data['providers'][ $name ]
			: array();

		foreach ( $patch as $field => $value ) {
			$current[ $field ] = $value;
		}

		$data['providers'][ $name ] = $current;

		update_option( self::OPTION_NAME, wp_json_encode( $data ) );
	}

	public static function folder_template(): string {
		return self::get()['folder_template'] ?? self::default_folder_template();
	}

	public static function max_filesize_mb(): float {
		return self::normalize_filesize_value( self::get()['max_filesize_mb'] ?? 0 );
	}

	public static function allowed_file_types(): array {
		return self::get()['allowed_file_types'] ?? array();
	}

	public static function debug_enabled(): bool {
		return ! empty( self::get()['debug_enabled'] );
	}

	private static function get_raw_option(): array {
		$stored = get_option( self::OPTION_NAME, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			$data = json_decode( $stored, true );
		} elseif ( is_array( $stored ) ) {
			$data = $stored;
		} else {
			$data = array();
		}

		return is_array( $data ) ? $data : array();
	}

	private static function merge_providers( array $current, array $defaults ): array {
		$merged = $defaults;

		foreach ( $defaults as $key => $default_values ) {
			$existing = isset( $current[ $key ] ) && is_array( $current[ $key ] )
				? $current[ $key ]
				: array();

			$merged[ $key ]['enabled'] = ! empty( $existing['enabled'] );

			foreach ( $default_values as $field => $value ) {
				if ( 'enabled' === $field ) {
					continue;
				}

				$merged[ $key ][ $field ] = isset( $existing[ $field ] )
					? (string) $existing[ $field ]
					: '';
			}
		}

		return $merged;
	}

	private static function normalize_filesize_value( $value ): float {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( $value ) );
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
}
