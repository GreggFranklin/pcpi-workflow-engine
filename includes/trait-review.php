<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Review {

	/**
	 * Normalize a Gravity Forms List field stored value into an array.
	 *
	 * In entries, List field values are commonly stored as a serialized string.
	 * GF list rendering expects an array; PHP 8+ will fatal if GF calls count() on a string.
	 *
	 * @param mixed $val
	 * @return array
	 */
	private static function normalize_list_field_value( $val ): array {

		if ( is_array( $val ) ) {
			return $val;
		}

		if ( is_string( $val ) && $val !== '' ) {
			$maybe = maybe_unserialize( $val );
			if ( is_array( $maybe ) ) {
				return $maybe;
			}
		}

		if ( is_string( $val ) && $val !== '' ) {
			$maybe = json_decode( $val, true );
			if ( is_array( $maybe ) ) {
				return $maybe;
			}
		}

		return [];
	}

	/**
	 * Determine the "current page" of a multipage form during render.
	 *
	 * IMPORTANT:
	 * When you click Next/Prev, GF posts BOTH:
	 * - gform_source_page_number_{ID} (the page you were on)
	 * - gform_target_page_number_{ID} (the page GF will render next)
	 *
	 * For gating UI on the *rendered* page, we must prefer TARGET first.
	 *
	 * @param int $form_id
	 * @return int
	 */
	private static function get_current_page_for_form( int $form_id ): int {

		$form_id = absint( $form_id );
		if ( $form_id <= 0 ) {
			return 1;
		}

		$candidates = [
			// Prefer TARGET (the page being rendered after paging).
			'gform_target_page_number_' . $form_id,
			'gform_target_page_number',

			// Then SOURCE/current variants.
			'gform_source_page_number_' . $form_id,
			'gform_source_page_number',
			'gform_page_number_' . $form_id,
			'gform_page_number',
			'gform_current_page',
		];

		foreach ( $candidates as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$p = absint( $_POST[ $k ] );
				return $p > 0 ? $p : 1;
			}
		}

		// Rare fallback.
		if ( isset( $_GET['page'] ) ) {
			$p = absint( $_GET['page'] );
			return $p > 0 ? $p : 1;
		}

		return 1;
	}

	/**
	 * Get last page number for a form array.
	 *
	 * @param array $form
	 * @return int
	 */
	private static function get_last_page_for_form( array $form ): int {
		if ( class_exists( 'GFFormDisplay' ) ) {
			$lp = absint( GFFormDisplay::get_max_page_number( $form ) );
			return $lp > 0 ? $lp : 1;
		}
		return 1;
	}

	/**
	 * Review: prefill the source (Questionnaire) form with an existing entry_id, but render it as read-only.
	 * This avoids brittle 300+ field mappings because we reuse the source form itself.
	 */
	public static function maybe_prefill_review_source_form( $form ) {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return $form;
		}

		$source_form_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		if ( ! $source_form_id || (int) rgar( $form, 'id' ) !== $source_form_id ) {
			return $form;
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			self::log( 'GFAPI not available.' );
			return $form;
		}

		$entry_id = (int) ( $ctx['entry_id'] ?? 0 );
		if ( ! $entry_id ) {
			self::log( 'No entry_id in URL.' );
			return $form;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry['id'] ) ) {
			self::log( 'Entry not found.' );
			return $form;
		}

		if ( (int) rgar( $entry, 'form_id' ) !== $source_form_id ) {
			self::log( 'Entry form_id mismatch.' );
			return $form;
		}

		$allowed_request_keys = [];

		foreach ( (array) rgar( $form, 'fields' ) as &$field ) {

			if ( empty( $field->type ) ) {
				continue;
			}

			if ( in_array( (string) $field->type, [ 'html', 'section', 'page', 'captcha' ], true ) ) {
				continue;
			}

			// Multi-input fields.
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as &$input ) {
					$input_id = (string) rgar( $input, 'id' );
					$val      = rgar( $entry, $input_id );
					if ( $val !== null && $val !== '' ) {
						$input['defaultValue'] = $val;
						$_POST[ 'input_' . str_replace( '.', '_', $input_id ) ]    = $val;
						$_REQUEST[ 'input_' . str_replace( '.', '_', $input_id ) ] = $val;
					}
					$allowed_request_keys[] = 'input_' . str_replace( '.', '_', $input_id );
				}
				continue;
			}

			// Single input.
			$field_id = (string) $field->id;
			$val      = rgar( $entry, $field_id );

			// List fields: keep defaultValue as STRING, prime request globals with ARRAY.
			if ( (string) $field->type === 'list' ) {

				$list_val = self::normalize_list_field_value( $val );

				$field->defaultValue = '';

				$_POST[ 'input_' . $field_id ]    = $list_val;
				$_REQUEST[ 'input_' . $field_id ] = $list_val;

				$allowed_request_keys[] = 'input_' . $field_id;
				continue;
			}

			if ( $val !== null && $val !== '' ) {
				$field->defaultValue = $val;
				$_POST[ 'input_' . $field_id ]    = $val;
				$_REQUEST[ 'input_' . $field_id ] = $val;
			}

			$allowed_request_keys[] = 'input_' . $field_id;
		}

		// Request injection fallback (helps some add-ons).
		foreach ( (array) $entry as $key => $val ) {

			$k = (string) $key;
			if ( ! preg_match( '/^[0-9]+([.][0-9]+)?$/', $k ) ) {
				continue;
			}

			$rk = 'input_' . str_replace( '.', '_', $k );
			if ( ! in_array( $rk, $allowed_request_keys, true ) ) {
				continue;
			}

			// Don't overwrite list field arrays we normalized above.
			if ( isset( $_POST[ $rk ] ) && is_array( $_POST[ $rk ] ) ) {
				continue;
			}

			$_POST[ $rk ]    = $val;
			$_REQUEST[ $rk ] = $val;
		}

		self::log( "Review prefill ok: source_form={$source_form_id} entry_id={$entry_id} workflow={$ctx['workflow_key']}" );

		return $form;
	}

	/**
	 * Force read-only markup for the source (Questionnaire) form while in review mode.
	 */
	public static function maybe_force_readonly_markup( $content, $field, $value, $lead_id, $form_id ) {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return $content;
		}

		$source_form_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		if ( (int) $form_id !== $source_form_id ) {
			return $content;
		}

		$content = preg_replace(
			'/<input([^>]*type=(\"|\\\')(text|email|number|tel|url|search|password|date|time|datetime-local|month|week)(\"|\\\')[^>]*)>/i',
			'<input$1 readonly="readonly" tabindex="-1" aria-readonly="true">',
			$content
		);

		$content = preg_replace(
			'/<textarea([^>]*)>/i',
			'<textarea$1 readonly="readonly" tabindex="-1" aria-readonly="true">',
			$content
		);

		$content = preg_replace( '/<select([^>]*)>/i', '<select$1 disabled="disabled" tabindex="-1" aria-disabled="true">', $content );

		$content = preg_replace(
			'/<input([^>]*type=(\"|\\\')(checkbox|radio)(\"|\\\')[^>]*)>/i',
			'<input$1 disabled="disabled" tabindex="-1" aria-disabled="true">',
			$content
		);

		return $content;
	}

	/**
	 * Review mode: ALWAYS hide submit button on SOURCE form.
	 */
	public static function maybe_hide_source_submit( $button, $form ) {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return $button;
		}

		$source_form_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		if ( $source_form_id && (int) rgar( $form, 'id' ) === $source_form_id ) {
			return '';
		}

		return $button;
	}

	/**
	 * Review mode: hide "Save & Continue" for SOURCE form.
	 */
	public static function maybe_hide_save_continue_on_review( $link, $form ): string {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return (string) $link;
		}

		$source_form_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		if ( $source_form_id && (int) rgar( $form, 'id' ) === $source_form_id ) {
			return '';
		}

		return (string) $link;
	}

	/**
	 * Review mode: show REVIEW form submit ONLY on LAST page of SOURCE form.
	 */
	public static function maybe_limit_submit_to_last_page_on_review( $button, $form ): string {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return (string) $button;
		}

		$review_form_id = (int) ( $ctx['workflow']['review_form_id'] ?? 0 );
		$source_form_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );

		// Only gate the REVIEW form's submit button.
		if ( ! $review_form_id || (int) rgar( $form, 'id' ) !== $review_form_id ) {
			return (string) $button;
		}

		if ( ! $source_form_id || ! class_exists( 'GFAPI' ) ) {
			return (string) $button;
		}

		$source_form = GFAPI::get_form( $source_form_id );
		if ( empty( $source_form ) ) {
			return (string) $button;
		}

		$current_page = self::get_current_page_for_form( $source_form_id );
		$last_page    = self::get_last_page_for_form( $source_form );

		// Debug (this should now show when you click Next/Prev).
		self::debug_log( 'review submit gate', [
			'review_form_id' => $review_form_id,
			'source_form_id' => $source_form_id,
			'current_page'   => $current_page,
			'last_page'      => $last_page,
			// include a small sample of POST keys to confirm paging keys exist
			'post_keys'      => array_slice( array_keys( $_POST ), 0, 40 ),
		] );

		if ( $current_page < $last_page ) {
			return '';
		}

		return (string) $button;
	}

	/**
	 * Review: prefill hidden relationship fields on the Review form.
	 */
	public static function maybe_prefill_review_hidden_fields( $form ) {

		$ctx = self::get_context();
		if ( empty( $ctx['is_review_page'] ) || empty( $ctx['workflow'] ) ) {
			return $form;
		}

		$review_form_id = (int) ( $ctx['workflow']['review_form_id'] ?? 0 );
		if ( ! $review_form_id || (int) rgar( $form, 'id' ) !== $review_form_id ) {
			return $form;
		}

		$entry_id = (int) ( $ctx['entry_id'] ?? 0 ); // questionnaire entry id
		if ( ! $entry_id ) {
			return $form;
		}

		$fid_parent_q = (int) ( $ctx['workflow']['review_parent_questionnaire_field_id'] ?? 0 );
		$fid_parent_a = (int) ( $ctx['workflow']['review_parent_applicant_field_id'] ?? 0 );

		$parent_applicant_entry_id = 0;

		if ( ! empty( $ctx['workflow']['questionnaire_parent_applicant_field_id'] ) && class_exists( 'GFAPI' ) ) {
			$q_entry = GFAPI::get_entry( $entry_id );
			if ( ! is_wp_error( $q_entry ) && ! empty( $q_entry['id'] ) ) {
				$parent_applicant_entry_id = absint( rgar( $q_entry, (string) $ctx['workflow']['questionnaire_parent_applicant_field_id'] ) );
			}
		}

		foreach ( (array) rgar( $form, 'fields' ) as &$field ) {

			if ( (string) $field->type !== 'hidden' ) {
				continue;
			}

			$field_id = (int) $field->id;

			if ( $fid_parent_q && $field_id === $fid_parent_q ) {
				$field->defaultValue = (string) $entry_id;
				$_POST[ 'input_' . $field_id ]    = (string) $entry_id;
				$_REQUEST[ 'input_' . $field_id ] = (string) $entry_id;
				continue;
			}

			if ( $fid_parent_a && $parent_applicant_entry_id && $field_id === $fid_parent_a ) {
				$field->defaultValue = (string) $parent_applicant_entry_id;
				$_POST[ 'input_' . $field_id ]    = (string) $parent_applicant_entry_id;
				$_REQUEST[ 'input_' . $field_id ] = (string) $parent_applicant_entry_id;
				continue;
			}
		}

		return $form;
	}
}
