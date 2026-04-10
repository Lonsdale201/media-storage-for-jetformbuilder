<?php

namespace MediaStorage\JetFormBuilder\Storage;

use Jet_Form_Builder\Classes\Resources\Uploaded_Collection;
use Jet_Form_Builder\Classes\Resources\Uploaded_File;
use Jet_Form_Builder\Classes\Resources\Uploaded_File_Path;
use Jet_Form_Builder\Form_Handler;
use MediaStorage\JetFormBuilder\Plugin;
use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use MediaStorage\JetFormBuilder\Storage\FormSettings;
use MediaStorage\JetFormBuilder\Storage\ProviderRegistry;
use MediaStorage\JetFormBuilder\Storage\Providers\ProviderInterface;

class Manager {

	/**
	 * @var ProviderInterface[]
	 */
	private array $providers = array();

	public function __construct() {
		$this->providers = ProviderRegistry::instantiate();
	}

	public function register(): void {
		add_action(
			'jet-form-builder/form-handler/after-send',
			array( $this, 'handle_submission' ),
			25,
			2
		);
	}

	public function handle_submission( Form_Handler $handler, bool $is_success ): void {
		if ( ! $is_success ) {
			if ( SettingsRepository::debug_enabled() ) {
				error_log( '[Media Storage] Skipped — form submission was not successful.' );
			}

			return;
		}

		if ( ! function_exists( 'jet_fb_context' ) ) {
			return;
		}

		$form_id = (int) $handler->get_form_id();

		$storage_settings = FormSettings::get_for_form( $form_id );
		$storage_settings = $this->maybe_apply_legacy_rules( $storage_settings, $form_id );

		if ( ! $this->rules_allow_storage( $storage_settings ) ) {
			return;
		}

		$files = $this->collect_files();
		$files = $this->filter_by_size_limit( $files, $storage_settings );
		$files = $this->filter_by_file_type( $files, $storage_settings );

		if ( empty( $files ) ) {
			return;
		}

		$any_provider_failed  = false;
		$any_provider_ran     = false;
		$debug                = SettingsRepository::debug_enabled();

		foreach ( $this->providers as $provider_id => $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}

			$rule = FormSettings::provider_rule( $storage_settings, $provider_id );

			if ( 'enabled' !== $rule['mode'] ) {
				continue;
			}

			$any_provider_ran = true;

			try {
				$provider->upload( $files, $handler, $rule );
			} catch ( \Throwable $exception ) {
				$any_provider_failed = true;

				if ( $debug ) {
					error_log(
						sprintf(
							'[Media Storage][%s] Provider failed: %s',
							$provider->get_id(),
							$exception->getMessage()
						)
					);
				}
			}
		}

		if ( ! $any_provider_ran || $any_provider_failed ) {
			if ( $debug && $any_provider_failed ) {
				error_log( '[Media Storage] Keeping original files because one or more providers failed.' );
			}

			return;
		}

		$delete = FormSettings::delete_original( $storage_settings );
		if ( null === $delete ) {
			$delete = SettingsRepository::delete_original_enabled();
		}

		if ( $delete ) {
			foreach ( $files as $entry ) {
				$this->delete_local_entry( $entry );
			}
		}
	}

	/**
	 * Collect uploaded files from JetFormBuilder context.
	 *
	 * Uses jet_fb_context()->resolve_files() directly instead of the deprecated
	 * jet_fb_request_handler()->get_files(), which merges raw pre-upload File objects
	 * on top of processed Uploaded_File objects — overwriting the paths we need.
	 */
	private function collect_files(): array {
		try {
			$context = jet_fb_context();
		} catch ( \Throwable $throwable ) {
			return array();
		}

		if ( ! is_object( $context ) || ! method_exists( $context, 'resolve_files' ) ) {
			return array();
		}

		$files = $context->resolve_files();

		if ( empty( $files ) || ! is_array( $files ) ) {
			return array();
		}

		$collected = array();

		foreach ( $files as $field => $value ) {
			$collected = array_merge(
				$collected,
				$this->normalize_file_value( $value, (string) $field )
			);
		}

		return $collected;
	}

	private function normalize_file_value( $value, string $field ): array {
		if ( empty( $value ) ) {
			return array();
		}

		if ( is_array( $value ) ) {
			$normalized = array();

			foreach ( $value as $item ) {
				$normalized = array_merge(
					$normalized,
					$this->normalize_file_value( $item, $field )
				);
			}

			return $normalized;
		}

		if ( $value instanceof Uploaded_Collection ) {
			return $this->normalize_collection( $value, $field );
		}

		if ( $value instanceof Uploaded_File_Path ) {
			$path = $value->get_attachment_file();

			$attachment_id = 0;
			if ( $value instanceof Uploaded_File ) {
				$raw_id = $value->get_attachment_id();
				$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;
			}

			return $this->create_entry( $path, $field, $this->get_url( $value ), basename( $path ?: '' ), $attachment_id );
		}

		return array();
	}

	private function normalize_collection( Uploaded_Collection $collection, string $field ): array {
		$paths = $this->split_values( $collection->get_attachment_file() );
		$urls  = $this->split_values( $collection->get_attachment_url() );
		$ids   = $this->split_values( $collection->get_attachment_id() );

		$entries = array();

		foreach ( $paths as $index => $path ) {
			$raw_id = $ids[ $index ] ?? '';
			$attachment_id = is_numeric( $raw_id ) ? (int) $raw_id : 0;

			$entries = array_merge(
				$entries,
				$this->create_entry(
					$path,
					$field,
					$urls[ $index ] ?? '',
					basename( $path ),
					$attachment_id
				)
			);
		}

		return $entries;
	}

	private function split_values( string $value ): array {
		if ( '' === $value ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $value ) );

		return array_values(
			array_filter(
				$parts,
				static function ( $part ) {
					return '' !== $part;
				}
			)
		);
	}

	private function create_entry( string $path, string $field, string $url, string $name, int $attachment_id = 0 ): array {
		if ( '' === $path || ! file_exists( $path ) ) {
			if ( SettingsRepository::debug_enabled() ) {
				error_log(
					sprintf(
						'[Media Storage] create_entry skipped — path empty or missing: "%s" (field: %s)',
						$path,
						$field
					)
				);
			}

			return array();
		}

		return array(
			array(
				'field'         => $field,
				'path'          => $path,
				'url'           => $url,
				'name'          => $name ?: basename( $path ),
				'attachment_id' => $attachment_id,
			),
		);
	}

	/**
	 * Delete the local file (and WP attachment if applicable).
	 */
	private function delete_local_entry( array $entry ): void {
		$path          = $entry['path'] ?? '';
		$attachment_id = $entry['attachment_id'] ?? 0;

		if ( '' === $path ) {
			return;
		}

		$debug = SettingsRepository::debug_enabled();

		if ( $attachment_id > 0 ) {
			if ( ! function_exists( 'wp_delete_attachment' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}

			$result = wp_delete_attachment( $attachment_id, true );

			if ( $debug ) {
				error_log(
					sprintf(
						'[Media Storage] wp_delete_attachment( %d, true ) — %s (path: %s)',
						$attachment_id,
						$result ? 'OK' : 'FAILED',
						$path
					)
				);
			}

			return;
		}

		if ( ! function_exists( 'wp_delete_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_delete_file( $path );

		if ( $debug ) {
			$still_exists = file_exists( $path );
			error_log(
				sprintf(
					'[Media Storage] wp_delete_file( %s ) — %s',
					$path,
					$still_exists ? 'FAILED (file still exists)' : 'OK'
				)
			);
		}
	}

	private function maybe_apply_legacy_rules( array $rules, int $form_id ): array {
		if ( ! empty( $rules['migrated'] ) ) {
			return $rules;
		}

		$legacy_toggle = $this->legacy_toggle( $form_id );

		if ( false === $legacy_toggle ) {
			foreach ( $rules['providers'] as $id => $config ) {
				$rules['providers'][ $id ]['mode'] = 'enabled';
			}
		}

		return $rules;
	}

	private function rules_allow_storage( array $rules ): bool {
		foreach ( $rules['providers'] as $config ) {
			if ( 'enabled' === ( $config['mode'] ?? 'disabled' ) ) {
				return true;
			}
		}

		return false;
	}

	private function filter_by_size_limit( array $files, array $settings ): array {
		$limit_mb = FormSettings::max_filesize_mb( $settings );
		if ( null === $limit_mb ) {
			$limit_mb = SettingsRepository::max_filesize_mb();
		}

		if ( $limit_mb <= 0 ) {
			return $files;
		}

		$limit_bytes = $limit_mb * MB_IN_BYTES;

		$filtered = array();

		foreach ( $files as $entry ) {
			$size = @filesize( $entry['path'] );
			if ( false === $size ) {
				$filtered[] = $entry;
				continue;
			}

				if ( $size > $limit_bytes ) {
					if ( SettingsRepository::debug_enabled() ) {
						$limit_display = rtrim( rtrim( sprintf( '%.4f', $limit_mb ), '0' ), '.' );
						error_log( sprintf( '[Media Storage] Skipped %s because it exceeds the %sMB limit.', $entry['name'], $limit_display ) );
					}
					continue;
				}

			$filtered[] = $entry;
		}

		return $filtered;
	}

	private function filter_by_file_type( array $files, array $settings ): array {
		$allowed = FormSettings::allowed_file_types( $settings );
		if ( null === $allowed ) {
			$allowed = SettingsRepository::allowed_file_types();
		}

		if ( empty( $allowed ) ) {
			return $files;
		}

		$debug    = SettingsRepository::debug_enabled();
		$filtered = array();

		foreach ( $files as $entry ) {
			$name     = $entry['name'];
			$filetype = wp_check_filetype( $name );
			$mime     = $filetype['type'] ?? '';

			if ( '' !== $mime && ! in_array( $mime, $allowed, true ) ) {
				if ( $debug ) {
					error_log( sprintf( '[Media Storage] Skipped %s — MIME type "%s" is not in the allowed list.', $name, $mime ) );
				}
				continue;
			}

			$blocked_mime = $this->check_intermediate_extensions( $name, $allowed );

			if ( '' !== $blocked_mime ) {
				if ( $debug ) {
					error_log( sprintf( '[Media Storage] Skipped %s — intermediate extension resolves to disallowed MIME "%s" (double-extension check).', $name, $blocked_mime ) );
				}
				continue;
			}

			$filtered[] = $entry;
		}

		return $filtered;
	}

	/**
	 * Detect double-extension bypass (e.g. evil.php.jpg).
	 *
	 * Checks every intermediate extension segment. If any resolves to a known
	 * MIME type that is not in the allowed list, return that MIME type.
	 */
	private function check_intermediate_extensions( string $name, array $allowed ): string {
		$parts = explode( '.', $name );

		if ( count( $parts ) < 3 ) {
			return '';
		}

		array_shift( $parts );
		array_pop( $parts );

		foreach ( $parts as $ext ) {
			$check = wp_check_filetype( 'file.' . strtolower( $ext ) );

			if ( ! empty( $check['type'] ) && ! in_array( $check['type'], $allowed, true ) ) {
				return $check['type'];
			}
		}

		return '';
	}

	private function legacy_toggle( int $form_id ): ?bool {
		if ( $form_id <= 0 ) {
			return null;
		}

		$raw = get_post_meta( $form_id, Plugin::META_DISABLE, true );

		if ( '' === $raw || null === $raw ) {
			return null;
		}

		if ( is_array( $raw ) ) {
			return isset( $raw['disabled'] ) ? (bool) $raw['disabled'] : null;
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return isset( $decoded['disabled'] ) ? (bool) $decoded['disabled'] : null;
	}

	/**
	 * @param Uploaded_File_Path|Uploaded_File $value
	 */
	private function get_url( $value ): string {
		if ( method_exists( $value, 'get_attachment_url' ) ) {
			return $value->get_attachment_url();
		}

		return '';
	}
}
