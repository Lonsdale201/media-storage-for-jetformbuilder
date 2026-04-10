<?php

namespace MediaStorage\JetFormBuilder\Storage;

use MediaStorage\JetFormBuilder\Plugin;
use MediaStorage\JetFormBuilder\Storage\ProviderRegistry;
use function json_decode;
use function str_replace;
use function wp_json_encode;
use function wp_unslash;

class FormSettings {

	public const DEFAULT_FOLDER = 'default';

	/**
	 * Return the default storage state stored in post meta.
	 */
	public static function defaults(): array {
		$providers = array();

		foreach ( ProviderRegistry::ids() as $id ) {
			$providers[ $id ] = self::provider_defaults();
		}

		return array(
			'migrated'           => false,
			'delete_original'    => null,
			'max_filesize_mb'    => null,
			'allowed_file_types' => null,
			'providers'          => $providers,
		);
	}

	/**
	 * JSON encoded defaults for register_post_meta.
	 */
	public static function defaults_json(): string {
		return wp_json_encode( self::defaults() );
	}

	/**
	 * Retrieve the storage preferences for a single form.
	 */
	public static function get_for_form( int $form_id ): array {
		$raw = get_post_meta( $form_id, Plugin::META_STORAGE, true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$data = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$data = $raw;
		} else {
			$data = array();
		}

		return self::normalize( $data );
	}

	/**
	 * Normalize any arbitrary payload into the expected shape.
	 */
	public static function normalize( $data ): array {
		$defaults = self::defaults();

		if ( ! is_array( $data ) ) {
			return $defaults;
		}

		$normalized = $defaults;

		$normalized['migrated'] = isset( $data['migrated'] )
			? (bool) $data['migrated']
			: false;

		if ( array_key_exists( 'delete_original', $data ) ) {
			$normalized['delete_original'] = self::sanitize_nullable_bool( $data['delete_original'] );
		}

		if ( array_key_exists( 'max_filesize_mb', $data ) ) {
			$normalized['max_filesize_mb'] = self::sanitize_size_value( $data['max_filesize_mb'] );
		}

		if ( array_key_exists( 'allowed_file_types', $data ) ) {
			$normalized['allowed_file_types'] = self::sanitize_file_types( $data['allowed_file_types'] );
		}

		if ( isset( $data['providers'] ) && is_array( $data['providers'] ) ) {
			foreach ( $normalized['providers'] as $key => $default ) {
				$current = isset( $data['providers'][ $key ] ) && is_array( $data['providers'][ $key ] )
					? $data['providers'][ $key ]
					: array();

				$normalized['providers'][ $key ] = array(
					'mode'   => self::sanitize_mode( $current['mode'] ?? $default['mode'] ),
					'folder' => self::sanitize_folder( $current['folder'] ?? $default['folder'] ),
				);
			}
		}

		return $normalized;
	}

	/**
	 * Fetch the rule for a single provider.
	 */
	public static function provider_rule( array $settings, string $provider_id ): array {
		return $settings['providers'][ $provider_id ] ?? self::provider_defaults();
	}

	/**
	 * Default structure for a provider rule.
	 */
	public static function provider_defaults(): array {
		return array(
			'mode'   => 'disabled',
			'folder' => self::DEFAULT_FOLDER,
		);
	}

	private static function sanitize_mode( string $mode ): string {
		return in_array( $mode, array( 'enabled', 'disabled' ), true )
			? $mode
			: 'disabled';
	}

	private static function sanitize_folder( string $folder ): string {
		$value = is_string( $folder ) ? trim( wp_unslash( $folder ) ) : '';

		return '' === $value ? self::DEFAULT_FOLDER : $value;
	}

	public static function max_filesize_mb( array $settings ): ?float {
		if ( ! array_key_exists( 'max_filesize_mb', $settings ) ) {
			return null;
		}

		return self::sanitize_size_value( $settings['max_filesize_mb'] );
	}

	public static function delete_original( array $settings ): ?bool {
		if ( ! array_key_exists( 'delete_original', $settings ) ) {
			return null;
		}

		return self::sanitize_nullable_bool( $settings['delete_original'] );
	}

	public static function allowed_file_types( array $settings ): ?array {
		if ( ! array_key_exists( 'allowed_file_types', $settings ) ) {
			return null;
		}

		return self::sanitize_file_types( $settings['allowed_file_types'] );
	}

	private static function sanitize_nullable_bool( $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (bool) $value;
	}

	private static function sanitize_file_types( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		return array_values( array_filter( $value, 'is_string' ) );
	}

	private static function sanitize_size_value( $value ): ?float {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( is_string( $value ) ) {
			$value = str_replace( ',', '.', trim( wp_unslash( $value ) ) );
		}

		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		if ( $number < 0 ) {
			return -1.0;
		}

		return (float) round( $number, 4 );
	}
}
