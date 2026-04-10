<?php

namespace MediaStorage\JetFormBuilder\Storage\Providers;

use Jet_Form_Builder\Form_Handler;
use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Storage\FolderTemplate;
use MediaStorage\JetFormBuilder\Storage\FormSettings;
use Throwable;
use function get_post;
use function is_wp_error;
use function json_decode;
use function time;
use function trailingslashit;
use function wp_get_raw_referer;
use function wp_remote_get;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

class GoogleDriveProvider extends BaseProvider {

	private array $settings = array();

	private string $access_token = '';

	/**
	 * Cache of folder-path → Google Drive folder ID.
	 */
	private array $folder_cache = array();

	public function __construct() {
		$this->settings = SettingsRepository::provider( 'gdrive' );
	}

	public function get_id(): string {
		return 'gdrive';
	}

	public function is_enabled(): bool {
		if ( empty( $this->settings['enabled'] ) ) {
			return false;
		}

		return $this->has_refresh_credentials();
	}

	public function upload( array $files, Form_Handler $handler, array $context = array() ): void {
		$token = $this->resolve_access_token();

		if ( '' === $token ) {
			throw new \RuntimeException( 'Google Drive access token is unavailable.' );
		}

		foreach ( $files as $entry ) {
			$local_path = $entry['path'];

			if ( ! is_readable( $local_path ) ) {
				$this->log( 'File is not readable; skipping.', array( 'path' => $local_path ) );
				continue;
			}

			$remote_path = $this->build_remote_path( $entry, $handler, $context );
			$folder_id   = $this->ensure_folder_hierarchy( $token, dirname( $remote_path ) );

			if ( '' === $folder_id ) {
				$this->log( 'Could not resolve remote folder; skipping.', array( 'path' => $remote_path ) );
				continue;
			}

			$filename = basename( $remote_path );
			$mime     = mime_content_type( $local_path ) ?: 'application/octet-stream';
			$content  = file_get_contents( $local_path );

			if ( false === $content ) {
				$this->log( 'Could not read file contents.', array( 'path' => $local_path ) );
				continue;
			}

			try {
				$this->upload_file( $token, $folder_id, $filename, $mime, $content );
				$this->log( 'Upload complete.', array( 'remote' => $remote_path, 'local' => $local_path ) );
			} catch ( Throwable $exception ) {
				$this->log( 'Upload failed.', array( 'message' => $exception->getMessage(), 'remote' => $remote_path ) );
			}
		}
	}

	// ──────────────────────────────────────────────
	//  Token management
	// ──────────────────────────────────────────────

	private function has_refresh_credentials(): bool {
		return '' !== trim( $this->settings['refresh_token'] ?? '' )
			&& '' !== trim( $this->settings['client_id'] ?? '' )
			&& '' !== trim( $this->settings['client_secret'] ?? '' );
	}

	private function resolve_access_token(): string {
		if ( '' !== $this->access_token ) {
			return $this->access_token;
		}

		$token   = trim( $this->settings['access_token'] ?? '' );
		$expires = isset( $this->settings['access_token_expires_at'] )
			? (int) $this->settings['access_token_expires_at']
			: 0;

		$needs_refresh = '' === $token || ( $expires && ( $expires - 60 ) <= time() );

		if ( $needs_refresh && $this->has_refresh_credentials() ) {
			$this->refresh_access_token();
			$token = trim( $this->settings['access_token'] ?? '' );
		}

		if ( '' === $token ) {
			$this->log( 'Access token unavailable after refresh attempt.' );
		}

		$this->access_token = $token;

		return $token;
	}

	private function refresh_access_token(): void {
		$refresh_token = trim( $this->settings['refresh_token'] ?? '' );
		$client_id     = trim( $this->settings['client_id'] ?? '' );
		$client_secret = trim( $this->settings['client_secret'] ?? '' );

		if ( '' === $refresh_token || '' === $client_id || '' === $client_secret ) {
			$this->log( 'Refresh skipped; credentials incomplete.' );
			return;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Token refresh failed.', array( 'message' => $response->get_error_message() ) );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$this->log( 'Token refresh returned unexpected response.', array( 'code' => $code, 'body' => $body ) );
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

		SettingsRepository::update_provider_settings( 'gdrive', $patch );

		$this->settings = array_merge( $this->settings, $patch );
	}

	// ──────────────────────────────────────────────
	//  Google Drive API calls
	// ──────────────────────────────────────────────

	/**
	 * Upload a file to Google Drive using the multipart upload method.
	 */
	private function upload_file( string $token, string $parent_id, string $filename, string $mime, string $content ): void {
		$metadata = wp_json_encode(
			array(
				'name'    => $filename,
				'parents' => array( $parent_id ),
			)
		);

		$boundary = 'msjfb_' . wp_generate_password( 16, false, false );

		$body  = '--' . $boundary . "\r\n";
		$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
		$body .= $metadata . "\r\n";
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n";
		$body .= "Content-Transfer-Encoding: binary\r\n\r\n";
		$body .= $content . "\r\n";
		$body .= '--' . $boundary . '--';

		$response = wp_remote_post(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException(
				sprintf( 'Google Drive API returned HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) )
			);
		}
	}

	/**
	 * Find a child folder by name inside a parent, or null if not found.
	 */
	private function find_folder( string $token, string $parent_id, string $name ): ?string {
		$query = sprintf(
			"name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
			str_replace( "'", "\\'", $name ),
			$parent_id
		);

		$url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(
			array(
				'q'      => $query,
				'fields' => 'files(id)',
				'pageSize' => 1,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['files'][0]['id'] ) ) {
			return (string) $data['files'][0]['id'];
		}

		return null;
	}

	/**
	 * Create a folder inside a parent folder and return the new folder's ID.
	 */
	private function create_folder( string $token, string $parent_id, string $name ): ?string {
		$response = wp_remote_post(
			'https://www.googleapis.com/drive/v3/files',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'name'     => $name,
						'mimeType' => 'application/vnd.google-apps.folder',
						'parents'  => array( $parent_id ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Folder creation failed.', array( 'name' => $name, 'error' => $response->get_error_message() ) );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $data['id'] ) ) {
			return (string) $data['id'];
		}

		$this->log( 'Folder creation returned unexpected response.', array( 'name' => $name, 'code' => $code ) );

		return null;
	}

	/**
	 * Walk the folder path and create each segment as needed.
	 *
	 * @return string The Google Drive folder ID of the deepest segment.
	 */
	private function ensure_folder_hierarchy( string $token, string $path ): string {
		$root = $this->resolve_root_folder( $token );

		$segments = array_filter( explode( '/', $path ) );

		if ( empty( $segments ) ) {
			return $root;
		}

		$cache_key = '';
		$parent_id = $root;

		foreach ( $segments as $segment ) {
			$cache_key .= '/' . $segment;

			if ( isset( $this->folder_cache[ $cache_key ] ) ) {
				$parent_id = $this->folder_cache[ $cache_key ];
				continue;
			}

			$existing = $this->find_folder( $token, $parent_id, $segment );

			if ( $existing ) {
				$this->folder_cache[ $cache_key ] = $existing;
				$parent_id = $existing;
				continue;
			}

			$new_id = $this->create_folder( $token, $parent_id, $segment );

			if ( ! $new_id ) {
				$this->log( 'Cannot create folder segment.', array( 'segment' => $segment, 'path' => $path ) );
				return '';
			}

			$this->folder_cache[ $cache_key ] = $new_id;
			$parent_id = $new_id;
		}

		return $parent_id;
	}

	/**
	 * Resolve the root folder setting.
	 *
	 * Accepts a Google Drive folder ID or a plain folder name.
	 * If the value looks like a name, searches for it in the Drive root
	 * and creates it when it does not exist yet.
	 */
	private function resolve_root_folder( string $token ): string {
		$value = trim( $this->settings['folder_id'] ?? '' );

		if ( '' === $value ) {
			return 'root';
		}

		// Drive folder IDs are long alphanumeric strings (28+ chars, no spaces).
		// If the value matches that pattern, treat it as a raw ID.
		if ( preg_match( '/^[A-Za-z0-9_-]{20,}$/', $value ) ) {
			return $value;
		}

		// Treat as a folder name — look up or create inside Drive root.
		$cache_key = '_root_' . $value;

		if ( isset( $this->folder_cache[ $cache_key ] ) ) {
			return $this->folder_cache[ $cache_key ];
		}

		$existing = $this->find_folder( $token, 'root', $value );

		if ( $existing ) {
			$this->folder_cache[ $cache_key ] = $existing;
			return $existing;
		}

		$new_id = $this->create_folder( $token, 'root', $value );

		if ( $new_id ) {
			$this->folder_cache[ $cache_key ] = $new_id;
			return $new_id;
		}

		$this->log( 'Could not resolve root folder by name; falling back to Drive root.', array( 'name' => $value ) );

		return 'root';
	}

	// ──────────────────────────────────────────────
	//  Path building
	// ──────────────────────────────────────────────

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
				'form_id'    => $form_id,
				'form_name'  => $this->get_form_name( $form_id ),
				'field_slug' => $field_slug,
				'timestamp'  => $timestamp,
				'referer'    => wp_get_raw_referer() ?: ( $_SERVER['HTTP_REFERER'] ?? '' ),
			)
		);

		$directory = $resolved ?: 'JetFormBuilder';
		$filename  = sprintf(
			'%s-%s-%s',
			$field_slug,
			wp_date( 'His', $timestamp ),
			$entry['name']
		);

		$path = trailingslashit( $directory ) . $filename;

		return apply_filters(
			'media_storage_for_jetformbuilder/gdrive/path',
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
}
