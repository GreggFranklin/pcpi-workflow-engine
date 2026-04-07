<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Prefill {

/**
 * Build an automatic Applicant → Questionnaire map using matching Admin Labels.
 *
 * This is the "scale to 300+ fields" path:
 * - Put the SAME Admin Label on the Applicant field and the Questionnaire field.
 * - For multi-input fields (Name/Address/etc.), inputs are mapped by suffix (.3, .6, etc.).
 *
 * Returns [ questionnaire_input_id => applicant_input_id ].
 *
 * @param int $applicant_form_id
 * @param int $questionnaire_form_id
 * @return array<string,string>
 */
	private static function build_auto_map_by_admin_label( int $applicant_form_id, int $questionnaire_form_id ): array {

		$applicant_form_id     = absint( $applicant_form_id );
		$questionnaire_form_id = absint( $questionnaire_form_id );

		if ( ! class_exists( 'GFAPI' ) || ! $applicant_form_id || ! $questionnaire_form_id ) {
			return [];
		}

		$f1 = GFAPI::get_form( $applicant_form_id );
		$f2 = GFAPI::get_form( $questionnaire_form_id );

		if ( empty( $f1 ) || empty( $f2 ) ) {
			return [];
		}

		// Build Applicant index: adminLabel|label => field id / input ids
		$idx = [];

		foreach ( (array) rgar( $f1, 'fields' ) as $field ) {
			if ( ! is_object( $field ) || empty( $field->id ) ) {
				continue;
			}

			$label = (string) ( $field->adminLabel ?: $field->label );
			$label = trim( $label );
			if ( $label === '' ) {
				continue;
			}

			// Store the base field id
			$idx[ $label ] = (string) $field->id;

			// Store inputs by suffix so multi-input fields map correctly (e.g., 1.3 → 40.3).
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $in ) {
					$iid = (string) rgar( $in, 'id' );
					if ( $iid ) {
						$idx[ $label . '|' . $iid ] = $iid;
					}
				}
			}
		}

		$map = [];

		foreach ( (array) rgar( $f2, 'fields' ) as $field ) {
			if ( ! is_object( $field ) || empty( $field->id ) ) {
				continue;
			}

			$label = (string) ( $field->adminLabel ?: $field->label );
			$label = trim( $label );
			if ( $label === '' ) {
				continue;
			}

			// Multi-input field: map each input by suffix
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $in ) {
					$form2_input_id = (string) rgar( $in, 'id' );
					if ( ! $form2_input_id ) continue;

					// Find an applicant input with same suffix if possible.
					// We try label|{input_id} first (exact), then label base field id.
					if ( isset( $idx[ $label . '|' . $form2_input_id ] ) ) {
						$map[ $form2_input_id ] = (string) $idx[ $label . '|' . $form2_input_id ];
						continue;
					}

					// If we have applicant base id, try swapping the base: {app_id}{suffix}
					if ( isset( $idx[ $label ] ) ) {
						$app_base = (string) $idx[ $label ];
						// suffix includes .3, .6 etc
						$suffix = strstr( $form2_input_id, '.' );
						if ( $suffix ) {
							$candidate = $app_base . $suffix;
							$map[ $form2_input_id ] = $candidate;
						}
					}
				}
				continue;
			}

			// Single input field
			$form2_field_id = (string) $field->id;
			if ( isset( $idx[ $label ] ) ) {
				$map[ $form2_field_id ] = (string) $idx[ $label ];
			}
		}

		unset( $field, $input );
		return $map;
	}

	/**
	 * Detect the hidden field ID on the Questionnaire form for "parent applicant entry id".
	 *
	 * @param array<string,mixed> $wf
	 * @param array<string,mixed> $form
	 * @return int
	 */
	private static function detect_questionnaire_parent_applicant_field_id( array $wf, array $form ): int {

		// Preferred: workflow config explicit.
		if ( ! empty( $wf['questionnaire_parent_applicant_field_id'] ) ) {
			return absint( $wf['questionnaire_parent_applicant_field_id'] );
		}

		// Fallback: detect by inputName (parameter name).
		foreach ( (array) rgar( $form, 'fields' ) as $field ) {
			if ( ! is_object( $field ) || empty( $field->id ) ) continue;
			if ( (string) $field->type !== 'hidden' ) continue;

			$input_name = isset( $field->inputName ) ? strtolower( (string) $field->inputName ) : '';
			if ( in_array( $input_name, [ 'parent_applicant_entry_id', 'parent_applicant_entry', 'parent_applicant_eid' ], true ) ) {
				return absint( $field->id );
			}
		}

		return 0;
	}

	/**
	 * Questionnaire: prefill questionnaire fields from parent Applicant entry.
	 */
	public static function maybe_prefill_questionnaire_from_applicant( $form ) {

		if ( ! class_exists( 'GFAPI' ) ) {
			return $form;
		}

		$form_id = (int) rgar( $form, 'id' );
		if ( ! $form_id ) {
			return $form;
		}
		
		self::log( 'prefill: entered', [
			'form_id' => $form_id,
			'qs_key'  => self::QS_PARENT_APPLICANT_EID,
			'get_parent_applicant_entry_id' => isset($_GET['parent_applicant_entry_id']) ? (string) $_GET['parent_applicant_entry_id'] : '',
			'get' => array_keys( $_GET ),
		] );

		$parent_eid = isset( $_GET[ self::QS_PARENT_APPLICANT_EID ] ) ? absint( $_GET[ self::QS_PARENT_APPLICANT_EID ] ) : 0;
		if ( ! $parent_eid ) {
			return $form; // nothing to do
		}

		foreach ( self::workflows() as $key => $wf ) {

			$wf = (array) $wf;

			// Only run when rendering the questionnaire/source form for this workflow.
			if ( empty( $wf['source_form_id'] ) || (int) $wf['source_form_id'] !== $form_id ) continue;

			// If no applicant_form_id, we still allow the questionnaire to render; just skip validation/prefill.
			if ( empty( $wf['applicant_form_id'] ) ) continue;

			$app_entry = GFAPI::get_entry( $parent_eid );
			if ( is_wp_error( $app_entry ) || empty( $app_entry['id'] ) ) continue;
			if ( (int) rgar( $app_entry, 'form_id' ) !== (int) $wf['applicant_form_id'] ) continue;

			$map = (array) ( $wf['applicant_to_questionnaire_map'] ?? [] );
			$map = apply_filters( 'pcpi_workflow_engine_applicant_to_questionnaire_map', $map, $key, $wf, $form );

			if ( empty( $map ) ) {
				// Auto-map by matching Admin Labels (recommended for large forms).
				$map = self::build_auto_map_by_admin_label( (int) $wf['applicant_form_id'], $form_id );
			}

			if ( empty( $map ) ) {
				self::debug_log( 'Applicant→Questionnaire prefill SKIP (no field map)', [
					'workflow' => $key,
					'form_id'  => $form_id,
					'msg'      => 'No matching Admin Labels found. Ensure Applicant + Questionnaire identity fields share Admin Labels.',
				] );
				continue;
			}

			foreach ( (array) rgar( $form, 'fields' ) as &$field ) {

				if ( method_exists( $field, 'is_administrative' ) && $field->is_administrative() ) continue;

				if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
					foreach ( $field->inputs as &$input ) {
						$form2_input_id = (string) rgar( $input, 'id' );
						if ( ! isset( $map[ $form2_input_id ] ) ) continue;

						$form1_input_id = (string) $map[ $form2_input_id ];
						$val            = rgar( $app_entry, $form1_input_id );

						if ( $val === null || $val === '' ) continue;

						$input['defaultValue'] = $val;
						$_POST[ 'input_' . str_replace( '.', '_', $form2_input_id ) ] = $val;
					}
					unset( $input );
					continue;
				}

				$form2_field_id = (string) $field->id;
				if ( ! isset( $map[ $form2_field_id ] ) ) continue;

				$form1_field_id = (string) $map[ $form2_field_id ];
				$val            = rgar( $app_entry, $form1_field_id );

				if ( $val === null || $val === '' ) continue;

				$field->defaultValue = $val;
				$_POST[ 'input_' . $form2_field_id ] = $val;
			}

			// Populate hidden parent applicant field on questionnaire (configured OR auto-detected).
			$fid = self::detect_questionnaire_parent_applicant_field_id( $wf, $form );
			if ( $fid ) {
				$_POST[ 'input_' . $fid ] = (string) $parent_eid;

				// Ensure it also shows in the form render (handy for debugging).
				foreach ( (array) rgar( $form, 'fields' ) as &$f2 ) {
					if ( (int) $f2->id === (int) $fid ) {
						$f2->defaultValue = (string) $parent_eid;
						break;
					}
				}
			}
				unset( $f2 );

			$app_form_id = (int) ( $wf['applicant_form_id'] ?? 0 );

			if ( apply_filters( 'pcpi_wfe_enable_hardcoded_identity_fallback', false, $key, $wf, $form_id, $app_form_id ) ) {

			// Explicit Name prefill fallback (GF Name is a multi-input field; mapping can fail if admin labels differ).
			// Applicant Name field ID: 1  (inputs: 1.3 first, 1.2 middle, 1.6 last)
			// Questionnaire Name field ID: 40 (inputs: 40.3 first, 40.2 middle, 40.6 last)
			// If you change these field IDs, prefer setting matching Admin Labels so the auto-map picks them up.
			$__app_name_fid = 1;
			$__q_name_fid   = 40;
			$__first  = rgar( $app_entry, "{$__app_name_fid}.3" );
			$__middle = rgar( $app_entry, "{$__app_name_fid}.2" );
			$__last   = rgar( $app_entry, "{$__app_name_fid}.6" );
			if ( ($__first !== null && $__first !== '') || ($__middle !== null && $__middle !== '') || ($__last !== null && $__last !== '') ) {
				foreach ( (array) rgar( $form, 'fields' ) as &$__f ) {
					if ( (int) $__f->id !== (int) $__q_name_fid ) {
						continue;
					}
					// Name field uses sub-input IDs. Populate both field inputs and $_POST so GF renders + validates.
					if ( ! empty( $__f->inputs ) && is_array( $__f->inputs ) ) {
						foreach ( $__f->inputs as &$__in ) {
							$__iid = (string) rgar( $__in, 'id' );
							if ( $__iid === "{$__q_name_fid}.3" && $__first !== null && $__first !== '' ) {
								$__in['defaultValue'] = $__first;
								$_POST[ 'input_' . str_replace( '.', '_', $__iid ) ] = $__first;
							}
							if ( $__iid === "{$__q_name_fid}.2" && $__middle !== null && $__middle !== '' ) {
								$__in['defaultValue'] = $__middle;
								$_POST[ 'input_' . str_replace( '.', '_', $__iid ) ] = $__middle;
							}
							if ( $__iid === "{$__q_name_fid}.6" && $__last !== null && $__last !== '' ) {
								$__in['defaultValue'] = $__last;
								$_POST[ 'input_' . str_replace( '.', '_', $__iid ) ] = $__last;
							}
						}
						unset( $__in );
					}
					break;
				}
				unset( $__f );
			}


			// Explicit Email prefill fallback (single-input field; mapping can fail if admin labels differ).
			// Applicant Email field ID: 2
			// Questionnaire Email field ID: 15
			$__app_email_fid = 2;
			$__q_email_fid   = 15;

			$__email = rgar( $app_entry, (string) $__app_email_fid );

			if ( $__email !== null && $__email !== '' ) {
				foreach ( (array) rgar( $form, 'fields' ) as &$__f ) {
					if ( (int) $__f->id !== (int) $__q_email_fid ) {
						continue;
					}

					// Populate default + $_POST so GF renders + validates consistently.
					$__f->defaultValue = $__email;
					$_POST[ 'input_' . $__q_email_fid ] = $__email;

					break;
				}
				unset( $__f );
			}

			
			}

			self::log( "Applicant→Questionnaire prefill ok: workflow={$key} applicant_eid={$parent_eid} form={$form_id}" );
			return $form;
		}

		return $form;
	}

	/**
	 * Questionnaire: populate staff email hidden field.
	 */
	public static function maybe_populate_staff_email( $form ) {

		if ( ! is_user_logged_in() ) {
			return $form;
		}

		$form_id = (int) rgar( $form, 'id' );
		if ( ! $form_id ) {
			return $form;
		}

		foreach ( self::workflows() as $key => $wf ) {

			$wf = (array) $wf;

			if ( empty( $wf['source_form_id'] ) || (int) $wf['source_form_id'] !== $form_id ) continue;
			if ( empty( $wf['staff_email_field_id'] ) ) continue;

			$fid = (int) $wf['staff_email_field_id'];

			$user  = wp_get_current_user();
			$email = (string) $user->user_email;
			if ( ! $email ) continue;

			$_POST[ 'input_' . $fid ] = $email;

			foreach ( (array) rgar( $form, 'fields' ) as &$field ) {
				if ( (int) $field->id === $fid ) {
					$field->defaultValue = $email;
					break;
				}
				unset( $__f );
			}

			self::log( "Populated staff email for workflow={$key} form={$form_id} field_id={$fid}" );
			return $form;
		}

		unset( $field, $f2, $__f, $__in );
		return $form;
	}
}