<?php

namespace MediaStorage\JetFormBuilder\Storage\Providers;

use Jet_Form_Builder\Form_Handler;

interface ProviderInterface {

	public function get_id(): string;

	public function is_enabled(): bool;

	/**
	 * @param array $files   Normalized file entries collected by the manager.
	 * @param array $context Form-specific configuration (usage, folder, etc.).
	 */
	public function upload( array $files, Form_Handler $handler, array $context = array() ): void;
}
