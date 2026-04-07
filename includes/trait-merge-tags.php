<?php
/**
 * Trait: Merge Tags
 *
 * Registers and replaces custom Gravity Forms merge tags related to the PCPI workflow.
 *
 * Provides:
 * - {pcpi_questionnaire_link} (smart: URL in href="", full anchor when standalone)
 * - {pcpi_questionnaire_url}  (legacy alias; URL only)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PCPI_WFE_Trait_Merge_Tags {

	/**
	 * Register custom merge tags so they show in the Gravity Forms merge tag picker.
	 *
	 * @param array<int,array{label:string,tag:string}> $merge_tags
	 * @param int $form_id
	 * @param array<string,mixed>|null $fields
	 * @param bool $element_id
	 * @return array<int,array{label:string,tag:string}>
	 */
	public static function register_custom_merge_tags( array $merge_tags, $form_id, $fields = null, $element_id = false ): array {

		$merge_tags[] = [
			'label' => 'PCPI Questionnaire Link (Smart)',
			'tag'   => '{pcpi_questionnaire_link}',
		];

		$merge_tags[] = [
			'label' => 'PCPI Questionnaire URL (Legacy)',
			'tag'   => '{pcpi_questionnaire_url}',
		];

		return $merge_tags;
	}

	/**
	 * Replace custom merge tags in notification bodies, confirmations, etc.
	 *
	 * Signature matches Gravity Forms' gform_replace_merge_tags filter.
	 *
	 * @param string $text
	 * @param array<string,mixed>|null $form
	 * @param array<string,mixed>|null $entry
	 * @param bool $url_encode
	 * @param bool $esc_html
	 * @param bool $nl2br
	 * @param string $format
	 * @return string
	 */
	public static function replace_custom_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

		// Gravity Forms may pass non-string values through this filter (e.g. List field defaults as arrays).
		// Merge tags only apply to strings, so pass non-strings through unchanged to avoid PHP 8+ TypeErrors.
		if ( ! is_string( $text ) || $text === '' ) {
			return $text;
		}

		$has_smart  = ( strpos( $text, '{pcpi_questionnaire_link}' ) !== false );
		$has_legacy = ( strpos( $text, '{pcpi_questionnaire_url}' ) !== false );

		if ( ! $has_smart && ! $has_legacy ) {
			return $text;
		}

		if ( empty( $entry ) || ! is_array( $entry ) ) {
			return str_replace( [ '{pcpi_questionnaire_link}', '{pcpi_questionnaire_url}' ], '', $text );
		}

		$url = self::build_questionnaire_url( $entry );
		if ( $url === '' ) {
			// Leave empty so you can detect failures in emails quickly.
			self::debug_log( 'merge tag: questionnaire url empty', [
				'entry_id' => isset( $entry['id'] ) ? absint( $entry['id'] ) : 0,
			] );

			return str_replace( [ '{pcpi_questionnaire_link}', '{pcpi_questionnaire_url}' ], '', $text );
		}

		// URL forms:
		// - For href="", NEVER rawurlencode the full URL (it will break ://).
		$url_for_href = $url;

		// - For legacy tag and non-href contexts, honor $url_encode.
		$url_for_text = $url_encode ? rawurlencode( $url ) : $url;

		// 1) Legacy tag always becomes URL-only.
		if ( $has_legacy ) {
			$text = str_replace( '{pcpi_questionnaire_url}', $url_for_text, $text );
		}

		// 2) Smart tag:
		//    (a) If used in href="...{pcpi_questionnaire_link}..." => replace with URL-only.
		if ( $has_smart ) {
			$text = preg_replace(
				'/(href\s*=\s*")[^"]*\{pcpi_questionnaire_link\}([^"]*")/i',
				'$1' . esc_url( $url_for_href ) . '$2',
				$text
			);
		}

		//    (b) Remaining standalone {pcpi_questionnaire_link} becomes:
		//        - HTML: full <a> tag with workflow-aware label
		//        - Text: "Label: URL"
		if ( $has_smart && strpos( $text, '{pcpi_questionnaire_link}' ) !== false ) {

			$workflow_key = self::resolve_workflow_key_from_entry_for_merge_tags( $entry );
			$wf           = self::get_workflow( $workflow_key );

			// Prefer explicit questionnaire label if you ever add it to the registry.
			$label = '';
			if ( ! empty( $wf['questionnaire_label'] ) ) {
				$label = (string) $wf['questionnaire_label'];
			} else {
				$label = self::get_workflow_label( $workflow_key );
			}
			if ( $label === '' ) {
				$label = 'Questionnaire';
			}

			/**
			 * Filter: pcpi_workflow_engine_questionnaire_link_text
			 *
			 * @param string $label
			 * @param string $workflow_key
			 * @param array<string,mixed> $workflow
			 * @param array<string,mixed> $entry
			 */
			$label = (string) apply_filters( 'pcpi_workflow_engine_questionnaire_link_text', $label, $workflow_key, $wf, $entry );

			/**
			 * Filter: pcpi_workflow_engine_questionnaire_link_url
			 *
			 * @param string $url
			 * @param string $workflow_key
			 * @param array<string,mixed> $workflow
			 * @param array<string,mixed> $entry
			 */
			$url_filtered = (string) apply_filters( 'pcpi_workflow_engine_questionnaire_link_url', $url, $workflow_key, $wf, $entry );

			if ( $url_filtered === '' ) {
				$url_filtered = $url;
			}

			if ( strtolower( (string) $format ) === 'html' ) {
				$anchor = '<a href="' . esc_url( $url_filtered ) . '">' . esc_html( $label ) . '</a>';
				$text   = str_replace( '{pcpi_questionnaire_link}', $anchor, $text );
			} else {
				$plain = $label . ': ' . $url_filtered;
				$text  = str_replace( '{pcpi_questionnaire_link}', $plain, $text );
			}
		}

		return $text;
	}

	/**
	 * Resolve workflow key from an entry for merge-tag usage.
	 *
	 * We cannot call the engine's private resolver from a trait, so we mirror the same logic:
	 * - If any workflow defines applicant_workflow_field_id and the entry has a value, use it.
	 * - Otherwise fall back to context workflow_key, then 'polygraph'.
	 *
	 * @param array<string,mixed> $entry
	 * @return string
	 */
	private static function resolve_workflow_key_from_entry_for_merge_tags( array $entry ): string {

		$workflows = self::get_workflows();
		foreach ( $workflows as $key => $wf ) {
			if ( empty( $wf['applicant_workflow_field_id'] ) ) {
				continue;
			}
			$fid = (string) absint( $wf['applicant_workflow_field_id'] );
			$raw = isset( $entry[ $fid ] ) ? (string) $entry[ $fid ] : '';
			if ( $raw !== '' ) {
				return sanitize_key( $raw );
			}
		}

		$ctx = self::get_context();
		if ( ! empty( $ctx['workflow_key'] ) ) {
			return sanitize_key( (string) $ctx['workflow_key'] );
		}

		return 'polygraph';
	}
}
