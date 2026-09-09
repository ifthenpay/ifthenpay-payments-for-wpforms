<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Api\WPForms\Process;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Renders a minimal label/value entry preview for the popup shown after a Pay By Link
 * payment completes (see Payments::render_confirmation_messages()'s "Show entry preview
 * after confirmation message" toggle). Intentionally simple — WPForms' own richer,
 * per-field-type entry preview rendering is a Pro-only feature not available to build on
 * here.
 */
class EntryPreviewRenderer
{
	/**
	 * @param array<int|string, mixed> $fields Decoded WPForms entry fields (wpforms_decode($entry->fields)).
	 */
	public static function render( array $fields ): string {
		$rows = '';

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( ( $field['type'] ?? '' ) === IFTP_PBL_FIELD_TYPE ) {
				continue;
			}

			$label = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
			$value = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';

			if ( $label === '' || $value === '' ) {
				continue;
			}

			$rows .= '<p class="iftp-pbl-entry-preview-row"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}

		if ( $rows === '' ) {
			return '';
		}

		return '<div class="iftp-pbl-entry-preview">' . $rows . '</div>';
	}
}
