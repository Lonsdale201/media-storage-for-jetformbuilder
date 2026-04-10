<?php

namespace MediaStorage\JetFormBuilder\Storage\Providers;

use MediaStorage\JetFormBuilder\Settings\SettingsRepository;

abstract class BaseProvider implements ProviderInterface {

	protected function log( string $message, array $context = array() ): void {
		if ( ! $this->should_log() ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';

		error_log(
			sprintf(
				'[Media Storage][%s] %s %s',
				$this->get_id(),
				$message,
				$payload
			)
		);
	}

	private function should_log(): bool {
		if ( SettingsRepository::debug_enabled() ) {
			return true;
		}

		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
