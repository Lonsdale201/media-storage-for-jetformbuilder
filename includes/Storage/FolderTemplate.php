<?php

namespace MediaStorage\JetFormBuilder\Storage;

use MediaStorage\JetFormBuilder\Settings\SettingsRepository;
use function home_url;
use function wp_date;
use function wp_parse_url;
use function wp_unslash;

class FolderTemplate {

	public static function resolve( string $template, array $context ): string {
		$template = trim( $template ) ?: SettingsRepository::default_folder_template();

		$timestamp = $context['timestamp'] ?? time();

		$replacements = array(
			'%formid%'            => self::format_form_id( $context['form_id'] ?? 0 ),
			'%formname%'          => self::sanitize_segment( $context['form_name'] ?? '' ),
			'%currentdate%'       => wp_date( 'Y/m/d', $timestamp ),
			'%currentyear%'       => wp_date( 'Y', $timestamp ),
			'%currentmonth%'      => wp_date( 'm', $timestamp ),
			'%currentday%'        => wp_date( 'd', $timestamp ),
			'%fieldslug%'         => self::sanitize_segment( $context['field_slug'] ?? 'field' ),
		);

		$resolved = strtr( $template, $replacements );

		$segments = array_filter( explode( '/', $resolved ), static function ( $segment ) {
			return '' !== trim( $segment );
		} );

		$segments = array_map( array( self::class, 'sanitize_segment' ), $segments );

		return implode( '/', array_filter( $segments ) );
	}

	private static function format_form_id( $form_id ): string {
		$form_id = (int) $form_id;

		return $form_id > 0 ? 'form-' . $form_id : 'form-0';
	}

	private static function sanitize_segment( string $segment ): string {
		$segment = trim( wp_unslash( $segment ) );

		if ( '' === $segment ) {
			return '';
		}

		$segment = str_replace( array( '\\', ':', '*', '?', '"', '<', '>', '|' ), '-', $segment );
		$segment = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $segment );

		return trim( $segment, '-' );
	}

}
