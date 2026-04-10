<?php

namespace MediaStorage\JetFormBuilder\Storage;

use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Storage\Providers\CloudflareR2Provider;
use MediaStorage\JetFormBuilder\Storage\Providers\DropboxProvider;
use MediaStorage\JetFormBuilder\Storage\Providers\GoogleDriveProvider;
use MediaStorage\JetFormBuilder\Storage\Providers\ProviderInterface;
use function __;
use function class_exists;

class ProviderRegistry {

	/**
	 * Map of provider identifiers to their configuration.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFINITIONS = array(
		'dropbox'       => array(
			'settings_key' => 'dropbox',
			'class'        => DropboxProvider::class,
			'label'        => 'Dropbox',
		),
		'cloudflare_r2' => array(
			'settings_key' => 'cloudflare',
			'class'        => CloudflareR2Provider::class,
			'label'        => 'Cloudflare R2',
		),
		'gdrive' => array(
			'settings_key' => 'gdrive',
			'class'        => GoogleDriveProvider::class,
			'label'        => 'Google Drive',
		),
	);

	/**
	 * Return the identifiers for all known providers.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::DEFINITIONS );
	}

	/**
	 * Instantiate available providers and key them by their public identifier.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public static function instantiate(): array {
		$instances = array();

		foreach ( self::DEFINITIONS as $definition ) {
			$class = $definition['class'] ?? null;

			if ( ! $class || ! class_exists( $class ) ) {
				continue;
			}

			$provider = new $class();

			if ( ! $provider instanceof ProviderInterface ) {
				continue;
			}

			$instances[ $provider->get_id() ] = $provider;
		}

		return $instances;
	}

	/**
	 * Build the payload consumed by the editor script.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function editor_providers(): array {
		$settings  = SettingsRepository::get();
		$providers = array();

		foreach ( self::DEFINITIONS as $id => $definition ) {
			$key     = $definition['settings_key'] ?? $id;
			$enabled = ! empty( $settings['providers'][ $key ]['enabled'] );

			$providers[] = array(
				'id'      => $id,
				'label'   => self::label( $id ),
				'enabled' => $enabled,
			);
		}

		return $providers;
	}

	/**
	 * Resolve a translated label for the given provider.
	 */
	public static function label( string $id ): string {
		switch ( $id ) {
			case 'cloudflare_r2':
				return __( 'Cloudflare R2', 'media-storage-for-jetformbuilder' );
			case 'gdrive':
				return __( 'Google Drive', 'media-storage-for-jetformbuilder' );
			case 'dropbox':
			default:
				return __( 'Dropbox', 'media-storage-for-jetformbuilder' );
		}
	}
}
