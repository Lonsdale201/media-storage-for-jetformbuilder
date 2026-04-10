<?php

namespace MediaStorage\JetFormBuilder\Rest;

use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use function current_user_can;
use function esc_html;
use function delete_transient;
use function get_current_user_id;
use function get_transient;
use function __;
use function rest_url;
use function sanitize_text_field;
use function set_transient;
use function time;
use function wp_generate_password;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
class DropboxRoutes {

	private const ROUTE_NAMESPACE = 'msjfb/v1';
	private const AUTHORIZE_ROUTE = '/dropbox/authorize';
	private const CALLBACK_ROUTE  = '/dropbox/callback';
	private const STATE_PREFIX    = 'msjfb_dropbox_state_';

	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::AUTHORIZE_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'ensure_manage_cap' ),
				'callback'            => array( $this, 'prepare_authorization' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::CALLBACK_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle_callback' ),
			)
		);
	}

	public function ensure_manage_cap(): bool {
		return current_user_can( 'manage_options' );
	}

	public function prepare_authorization( WP_REST_Request $request ) {
		$settings = SettingsRepository::provider( 'dropbox' );
		$app_key  = trim( $settings['app_key'] ?? '' );
		$app_secret = trim( $settings['app_secret'] ?? '' );

		if ( '' === $app_key || '' === $app_secret ) {
			return new WP_Error(
				'msjfb_dropbox_missing_credentials',
				__( 'Enter the Dropbox App key and secret before generating tokens.', 'media-storage-for-jetformbuilder' ),
				array( 'status' => 400 )
			);
		}

		$state = wp_generate_password( 20, false, false );

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'user_id' => get_current_user_id(),
				'created' => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$callback = $this->get_callback_url();

		$query = http_build_query(
			array(
				'client_id'           => $app_key,
				'response_type'       => 'code',
				'redirect_uri'        => $callback,
				'token_access_type'   => 'offline',
				'state'               => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$authorize_url = 'https://www.dropbox.com/oauth2/authorize?' . $query;

		$this->log( 'Prepared Dropbox authorization URL.', array( 'callback' => $callback ) );

		return array(
			'authorize_url' => $authorize_url,
			'state'         => $state,
			'callback_url'  => $callback,
		);
	}

	public function handle_callback( WP_REST_Request $request ) {
		$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
		$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );
		$error = sanitize_text_field( $request->get_param( 'error' ) ?? '' );

		if ( '' !== $error ) {
			$this->log( 'Dropbox OAuth returned error.', array( 'error' => $error ) );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => sprintf( __( 'Dropbox reported an error: %s', 'media-storage-for-jetformbuilder' ), $error ),
				)
			);
		}

		if ( '' === $code || '' === $state ) {
			$this->log( 'Invalid callback payload.', array( 'code' => $code, 'state' => $state ) );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => __( 'Missing authorization code or state.', 'media-storage-for-jetformbuilder' ),
				)
			);
		}

		$state_key     = self::STATE_PREFIX . $state;
		$state_payload = get_transient( $state_key );

		if ( ! $state_payload ) {
			$this->log( 'Dropbox state expired/missing.', array( 'state' => $state ) );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => __( 'The authorization request has expired. Please try again.', 'media-storage-for-jetformbuilder' ),
				)
			);
		}

		delete_transient( $state_key );

		$current_user  = get_current_user_id();
		$expected_user = (int) ( $state_payload['user_id'] ?? 0 );

		if ( ! $current_user || $current_user !== $expected_user ) {
			$this->log( 'State user mismatch — rejecting callback.', array( 'expected' => $expected_user, 'actual' => $current_user ) );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => __( 'Session mismatch. Please log in as the same user who started the authorization and try again.', 'media-storage-for-jetformbuilder' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->log( 'User lacks capability when finishing OAuth.' );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => __( 'You are not allowed to complete this action.', 'media-storage-for-jetformbuilder' ),
				)
			);
		}

		$result = $this->exchange_code_for_tokens( $code );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Token exchange failed.', array( 'message' => $result->get_error_message() ) );
			return $this->render_message(
				array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				)
			);
		}

		$this->log( 'Dropbox tokens saved via callback.' );

		return $this->render_message(
			array(
				'status' => 'success',
				'tokens' => $result,
				'message' => __( 'Dropbox tokens saved. You can close this window.', 'media-storage-for-jetformbuilder' ),
			)
		);
	}

	private function exchange_code_for_tokens( string $code ) {
		$settings = SettingsRepository::provider( 'dropbox' );
		$app_key  = trim( $settings['app_key'] ?? '' );
		$app_secret = trim( $settings['app_secret'] ?? '' );

		if ( '' === $app_key || '' === $app_secret ) {
			$this->log( 'Missing app credentials during token exchange.' );
			return new WP_Error(
				'msjfb_dropbox_missing_credentials',
				__( 'Dropbox App key or secret is missing. Save the settings before generating tokens.', 'media-storage-for-jetformbuilder' )
			);
		}

		$body = array(
			'code'          => $code,
			'grant_type'    => 'authorization_code',
			'client_id'     => $app_key,
			'client_secret' => $app_secret,
			'redirect_uri'  => $this->get_callback_url(),
		);

		$response = $this->request_with_curl( $body );

		if ( is_wp_error( $response ) ) {
			$this->log( 'cURL token request failed.', array( 'error' => $response->get_error_message() ) );
			$response = $this->request_with_wp_remote( $body );
		}

		if ( is_wp_error( $response ) ) {
			$this->log( 'wp_remote_post token request failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		$code    = $response['code'];
		$content = $response['body'];
		$data    = json_decode( $content, true );

		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$this->log( 'Dropbox token endpoint returned unexpected response.', array( 'code' => $code, 'error' => $data['error_description'] ?? $data['error'] ?? 'unknown' ) );
			return new WP_Error(
				'msjfb_dropbox_invalid_response',
				__( 'Dropbox did not return the expected tokens. Try again later.', 'media-storage-for-jetformbuilder' )
			);
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

		$this->log( 'Token exchange succeeded.', array( 'has_refresh' => ! empty( $patch['refresh_token'] ) ) );

		return $patch;
	}

	private function request_with_curl( array $body ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'msjfb_no_curl', 'cURL is not available.' );
		}

		$ch = curl_init( 'https://api.dropboxapi.com/oauth2/token' );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $body, '', '&' ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/x-www-form-urlencoded' ) );

		$content = curl_exec( $ch );
		$error   = curl_error( $ch );
		$code    = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $content || '' !== $error ) {
			return new WP_Error( 'msjfb_curl_error', $error ?: __( 'Unknown cURL error.', 'media-storage-for-jetformbuilder' ) );
		}

		return array(
			'code' => $code,
			'body' => $content,
		);
	}

	private function request_with_wp_remote( array $body ) {
		$response = wp_remote_post(
			'https://api.dropboxapi.com/oauth2/token',
			array(
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}

private function render_message( array $payload ) {
		$json_payload = wp_json_encode(
			array_merge( array( 'source' => 'msjfb-dropbox' ), $payload )
		);

		$script = '<script>(function(){try{if(window.opener){window.opener.postMessage(' . $json_payload . ',window.location.origin);}}catch(e){console.error(e);}}());</script>';

		$message = esc_html( $payload['message'] ?? '' );

		$token_html = '';
		if ( 'success' === ( $payload['status'] ?? '' ) && ! empty( $payload['tokens']['refresh_token'] ) ) {
			$token_html = '<div style="margin-top:12px;padding:10px;background:#f3f4f6;border-radius:6px;font-size:13px;">'
				. '<p style="margin:0 0 6px;font-weight:600;">' . esc_html__( 'Refresh Token (copy into the settings field if it did not auto-fill):', 'media-storage-for-jetformbuilder' ) . '</p>'
				. '<input type="text" value="' . esc_attr( $payload['tokens']['refresh_token'] ) . '" readonly onclick="this.select();" style="width:100%;padding:6px 8px;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;" />'
				. '</div>';
		}

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Dropbox OAuth</title>'
			. '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:24px;color:#1f2937;}</style>'
			. '</head><body><p>' . $message . '</p>' . $token_html . $script . '</body></html>';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html;
		exit;
	}

	private function get_callback_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . self::CALLBACK_ROUTE );
	}

	private function log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( '[MSJFB Dropbox] %s %s', $message, $payload ) );
	}
}
