<?php

namespace MediaStorage\JetFormBuilder\Storage\Providers;

use Jet_Form_Builder\Form_Handler;
use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Storage\FolderTemplate;
use MediaStorage\JetFormBuilder\Storage\FormSettings;
use Spatie\Dropbox\Client;
use Spatie\Dropbox\Exceptions\BadRequest;
use Throwable;
use function get_post;
use function json_decode;
use function is_wp_error;
use function time;
use function trailingslashit;
use function wp_get_raw_referer;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

class DropboxProvider extends BaseProvider {

	private ?Client $client = null;

	private array $settings = array();

	private array $created_folders = array();

	public function __construct() {
		$this->settings = SettingsRepository::provider( 'dropbox' );
	}

	public function get_id(): string {
		return 'dropbox';
	}

	public function is_enabled(): bool {
		if ( empty( $this->settings['enabled'] ) ) {
			return false;
		}

		if ( '' !== trim( $this->settings['access_token'] ?? '' ) ) {
			return true;
		}

		return $this->has_refresh_credentials();
	}

	public function upload( array $files, Form_Handler $handler, array $context = array() ): void {
		$client = $this->resolve_client();

		if ( ! $client ) {
			throw new \RuntimeException( 'Dropbox client could not be initialized (missing or invalid credentials).' );
		}

		foreach ( $files as $entry ) {
			$local_path = $entry['path'];

			if ( ! is_readable( $local_path ) ) {
				$this->log(
					'File is not readable; skipping Dropbox upload.',
					array( 'path' => $local_path )
				);
				continue;
			}

			$remote_path = $this->build_remote_path( $entry, $handler, $context );
			$this->ensure_remote_folder( $client, dirname( $remote_path ) );

			$handle = fopen( $local_path, 'rb' );

			if ( false === $handle ) {
				$this->log(
					'Could not open file pointer.',
					array( 'path' => $local_path )
				);
				continue;
			}

			try {
				$client->upload( $remote_path, $handle, 'add', true );
				$this->log(
					'Upload complete.',
					array(
						'remote' => $remote_path,
						'local'  => $local_path,
					)
				);
			} catch ( Throwable $exception ) {
				$this->log(
					'Dropbox upload failed',
					array(
						'message' => $exception->getMessage(),
						'remote'  => $remote_path,
					)
				);
			}

			fclose( $handle );
		}
	}

	private function resolve_client(): ?Client {
		if ( $this->client instanceof Client ) {
			return $this->client;
		}

		$token = $this->get_access_token();

		if ( '' === $token ) {
			$this->log( 'Dropbox access token missing.' );

			return null;
		}

		try {
			$this->client = new Client( $token );
		} catch ( Throwable $exception ) {
			$this->log(
				'Failed to initialize Dropbox client.',
				array( 'message' => $exception->getMessage() )
			);

			return null;
		}

		return $this->client;
	}

	private function get_access_token(): string {
		$token   = trim( $this->settings['access_token'] ?? '' );
		$expires = isset( $this->settings['access_token_expires_at'] )
			? (int) $this->settings['access_token_expires_at']
			: 0;

		$needs_refresh = '' === $token || ( $expires && ( $expires - 60 ) <= time() );

		if ( $needs_refresh && $this->has_refresh_credentials() ) {
			$this->refresh_access_token();
			$token = trim( $this->settings['access_token'] ?? '' );
		}

		return $token;
	}

	private function has_refresh_credentials(): bool {
		return '' !== trim( $this->settings['refresh_token'] ?? '' )
			&& '' !== trim( $this->settings['app_key'] ?? '' )
			&& '' !== trim( $this->settings['app_secret'] ?? '' );
	}

	private function refresh_access_token(): void {
		$refresh_token = trim( $this->settings['refresh_token'] ?? '' );
		$app_key       = trim( $this->settings['app_key'] ?? '' );
		$app_secret    = trim( $this->settings['app_secret'] ?? '' );

		if ( '' === $refresh_token || '' === $app_key || '' === $app_secret ) {
			$this->log( 'Dropbox refresh skipped; credentials incomplete.' );

			return;
		}

		$response = wp_remote_post(
			'https://api.dropboxapi.com/oauth2/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => $app_key,
					'client_secret' => $app_secret,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log(
				'Dropbox token refresh failed.',
				array( 'message' => $response->get_error_message() )
			);

			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$this->log(
				'Dropbox token refresh returned an unexpected response.',
				array(
					'code' => $code,
					'body' => $body,
				)
			);

			return;
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0;
		$expires_at = $expires_in > 0 ? (string) ( time() + $expires_in ) : '';

		$patch = array(
			'access_token'            => (string) $data['access_token'],
			'access_token_expires_at' => $expires_at,
		);

		if ( ! empty( $data['refresh_token'] ) ) {
			$patch['refresh_token'] = (string) $data['refresh_token'];
		}

		SettingsRepository::update_provider_settings( 'dropbox', $patch );

		$this->settings = array_merge( $this->settings, $patch );
	}

	private function build_remote_path( array $entry, Form_Handler $handler, array $context ): string {
		$form_id    = (int) $handler->get_form_id();
		$field_slug = sanitize_key( $entry['field'] ) ?: 'field';
		$timestamp  = current_time( 'timestamp' );
		$folder     = $context['folder'] ?? FormSettings::DEFAULT_FOLDER;

		$template = FormSettings::DEFAULT_FOLDER === $folder
			? SettingsRepository::folder_template()
			: $folder;

		$resolved = FolderTemplate::resolve(
			$template,
			array(
				'form_id'   => $form_id,
				'form_name' => $this->get_form_name( $form_id ),
				'field_slug'=> $field_slug,
				'timestamp' => $timestamp,
				'referer'   => wp_get_raw_referer() ?: ( $_SERVER['HTTP_REFERER'] ?? '' ),
			)
		);

		$directory = $resolved ?: 'JetFormBuilder';
		$filename  = sprintf(
			'%s-%s-%s',
			$field_slug,
			wp_date( 'His', $timestamp ),
			$entry['name']
		);

		$path = trailingslashit( '/' . ltrim( $directory, '/' ) ) . $filename;

		return apply_filters(
			'media_storage_for_jetformbuilder/dropbox/path',
			$path,
			$entry,
			$handler,
			$context
		);
	}

	private function get_form_name( int $form_id ): string {
		if ( $form_id <= 0 ) {
			return '';
		}

		$form = get_post( $form_id );

		return $form && ! empty( $form->post_title ) ? $form->post_title : '';
	}

	private function ensure_remote_folder( Client $client, string $folder ): void {
		$folder = trim( $folder );

		if ( '' === $folder || '.' === $folder ) {
			return;
		}

		$normalized = '/' . ltrim( $folder, '/' );
		$segments   = array_filter( explode( '/', $normalized ) );
		$current    = '';

		foreach ( $segments as $segment ) {
			$current .= '/' . $segment;

			if ( isset( $this->created_folders[ $current ] ) ) {
				continue;
			}

			try {
				$client->createFolder( $current );
			} catch ( BadRequest $exception ) {
				// Folder already exists; ignore.
			} catch ( Throwable $exception ) {
				$this->log(
					'Failed to provision Dropbox folder.',
					array(
						'folder'  => $current,
						'message' => $exception->getMessage(),
					)
				);
				break;
			}

			$this->created_folders[ $current ] = true;
		}
	}
}
