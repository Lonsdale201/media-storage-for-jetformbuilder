<?php

namespace MediaStorage\JetFormBuilder\Storage\Providers;

use Jet_Form_Builder\Form_Handler;
use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Storage\FolderTemplate;
use MediaStorage\JetFormBuilder\Storage\FormSettings;
use Throwable;
use function get_post;
use function sanitize_key;
use function trailingslashit;
use function wp_date;
use function wp_get_raw_referer;
use function wp_remote_request;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_body;

class CloudflareR2Provider extends BaseProvider {

	private array $settings = array();

	public function __construct() {
		$this->settings = SettingsRepository::provider( 'cloudflare' );
	}

	public function get_id(): string {
		return 'cloudflare_r2';
	}

	public function is_enabled(): bool {
		if ( empty( $this->settings['enabled'] ) ) {
			return false;
		}

		$required = array( 'account_id', 'access_key', 'secret_key', 'bucket' );

		foreach ( $required as $field ) {
			if ( empty( $this->settings[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	public function upload( array $files, Form_Handler $handler, array $context = array() ): void {
		$account_id = trim( $this->settings['account_id'] ?? '' );
		$access_key = trim( $this->settings['access_key'] ?? '' );
		$secret_key = trim( $this->settings['secret_key'] ?? '' );
		$bucket     = trim( $this->settings['bucket'] ?? '' );
		$region     = trim( $this->settings['region'] ?? '' );
		$region     = ( $region && preg_match( '/^[a-z0-9-]+$/i', $region ) )
			? strtolower( $region )
			: 'auto';

		if ( '' === $account_id || '' === $access_key || '' === $secret_key || '' === $bucket ) {
			throw new \RuntimeException( 'Cloudflare R2 credentials are incomplete.' );
		}

		$host = sprintf( '%s.r2.cloudflarestorage.com', $account_id );

		foreach ( $files as $entry ) {
			$local_path = $entry['path'];

			if ( ! is_readable( $local_path ) ) {
				$this->log( 'R2: file not readable', array( 'path' => $local_path ) );
				continue;
			}

			$content = file_get_contents( $local_path );

			if ( false === $content ) {
				$this->log( 'R2: failed to read file', array( 'path' => $local_path ) );
				continue;
			}

			$remote_key   = $this->build_object_key( $entry, $handler, $context );
			$content_type = mime_content_type( $local_path ) ?: 'application/octet-stream';

			try {
				$this->put_object( $host, $bucket, $remote_key, $content, $content_type, $access_key, $secret_key, $region );
				$this->log( 'R2 upload complete', array( 'key' => $remote_key ) );
			} catch ( Throwable $exception ) {
				$this->log(
					'R2 upload failed',
					array(
						'key'     => $remote_key,
						'message' => $exception->getMessage(),
					)
				);
			}
		}
	}

	// ──────────────────────────────────────────────
	//  S3-compatible PUT with AWS Signature V4
	// ──────────────────────────────────────────────

	private function put_object(
		string $host,
		string $bucket,
		string $key,
		string $body,
		string $content_type,
		string $access_key,
		string $secret_key,
		string $region
	): void {
		$service   = 's3';
		$now       = gmdate( 'Ymd\THis\Z' );
		$date_short = substr( $now, 0, 8 );

		$encoded_key = '/' . implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
		$url         = sprintf( 'https://%s/%s%s', $host, $bucket, $encoded_key );

		$payload_hash = hash( 'sha256', $body );

		$headers = array(
			'Host'                 => $host,
			'Content-Type'        => $content_type,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'          => $now,
		);

		// Canonical request.
		$signed_header_keys = array_keys( $headers );
		$signed_header_keys = array_map( 'strtolower', $signed_header_keys );
		sort( $signed_header_keys );

		$canonical_headers = '';
		foreach ( $signed_header_keys as $lk ) {
			foreach ( $headers as $k => $v ) {
				if ( strtolower( $k ) === $lk ) {
					$canonical_headers .= $lk . ':' . trim( $v ) . "\n";
					break;
				}
			}
		}

		$signed_headers_str = implode( ';', $signed_header_keys );

		$canonical_request = implode( "\n", array(
			'PUT',
			'/' . $bucket . $encoded_key,
			'', // no query string
			$canonical_headers,
			$signed_headers_str,
			$payload_hash,
		) );

		// String to sign.
		$scope          = $date_short . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash( 'sha256', $canonical_request );

		// Signing key.
		$date_key    = hash_hmac( 'sha256', $date_short, 'AWS4' . $secret_key, true );
		$region_key  = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', $service, $region_key, true );
		$signing_key = hash_hmac( 'sha256', 'aws4_request', $service_key, true );

		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$scope,
			$signed_headers_str,
			$signature
		);

		$headers['Authorization'] = $authorization;

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException(
				sprintf( 'R2 returned HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) )
			);
		}
	}

	// ──────────────────────────────────────────────
	//  Path building
	// ──────────────────────────────────────────────

	private function build_object_key( array $entry, Form_Handler $handler, array $context ): string {
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

		$path = trailingslashit( $directory ) . $filename;

		return apply_filters(
			'media_storage_for_jetformbuilder/cloudflare_r2/path',
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
