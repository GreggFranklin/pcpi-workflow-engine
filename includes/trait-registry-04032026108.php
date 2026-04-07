<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Registry {

	/**
	 * ------------------------------------------------------------------------
	 * WORKFLOW REGISTRY STRUCTURE (DUMMY EXAMPLE)
	 * ------------------------------------------------------------------------
	 *
	 * 'example_workflow' => [
	 *
	 *   // Human-readable label (used in UI / logs)
	 *   'label' => 'Example Workflow',
	 *
	 *   // ID links to Agency CPT 
	 *   'agency_id' => 123,
	 *
	 *   // Applicant Form ID (0 if NOT used)
	 *   'applicant_form_id' => 1,
	 *
	 *   // Field ID on Applicant form storing workflow key
	 *   'applicant_workflow_field_id' => 1005,
	 *
	 *   // Questionnaire form (REQUIRED)
	 *   'source_form_id' => 2,
	 *
	 *   // Review form (only if has_review = true)
	 *   'review_form_id' => 26,
	 *
	 *   // Whether review step exists
	 *   'has_review' => true,
	 *
	 *   // Questionnaire field storing Applicant Entry ID
	 *   'questionnaire_parent_applicant_field_id' => 579,
	 *
	 *   // Review field storing Questionnaire Entry ID
	 *   'review_parent_questionnaire_field_id' => 1,
	 *
	 *   // Review field storing Applicant Entry ID
	 *   'review_parent_applicant_field_id' => 3,
	 *
	 *   // Frontend paths (must start with /)
	 *   'questionnaire_page_path' => '/form-example-questionnaire/',
	 *   'review_page_path'        => '/form-example-review/',
	 *
	 *   // Gravity PDF ID (string)
	 *   'pdf_id' => 'abc123',
	 *
	 *   // Optional: kiosk mode for tablet UX
	 *   'entry_mode' => 'kiosk',
	 *
	 *   // Feature flags (UI/UX behavior)
	 *   'features' => [
	 *       'auto_scroll_radios' => true,
	 *       'mark_all_as_no'     => true,
	 *       'overlay_loader'     => true,
	 *       'disable_gf_spinner' => true,
	 *   ],
	 * ],
	 */

	private static function workflows(): array {

		$defaults = [

			'polygraph' => [
				'label' => 'Polygraph Questionnaire',
				'agency_id' => 641,
				'applicant_form_id'            => 1,
				'applicant_workflow_field_id'  => 1005,
				'source_form_id'               => 2,
				'review_form_id'               => 26,
				'has_review'                   => true,
				'questionnaire_parent_applicant_field_id' => 579,
				'review_parent_questionnaire_field_id'    => 1,
				'review_parent_applicant_field_id'        => 3,
				'questionnaire_page_path' => '/form-polygraph-questionnaire/',
				'review_page_path'        => '/form-polygraph-questionnaire-review/',
				'pdf_id'                  => '690f9d2e167ec',
				'features' => [],
			],

			'vast' => [
				'label' => 'VAST - Law Enforcement Questionnaire',
				'agency_id' => 641,
				'applicant_form_id'           => 1, // FIXED (was 0)
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 24,
				'review_form_id'              => 25,
				'has_review'                  => true,
				'questionnaire_parent_applicant_field_id' => 62,
				'review_parent_questionnaire_field_id'    => 1,
				'review_parent_applicant_field_id'        => 3,
				'questionnaire_page_path' => '/form-vast-questionnaire/', // FIXED typo
				'review_page_path'        => '/form-vast-questionnaire-review/',
				'pdf_id'                  => '698652e8ec09d',
				'entry_mode'              => 'kiosk',
				'features' => [
					'auto_scroll_radios' => true,
					'mark_all_as_no'     => true,
					'overlay_loader'     => true,
					'disable_gf_spinner' => true,
				],
			],

			'citrus_heights' => [
				'label' => 'Citrus Heights PD Questionnaire',
				'agency_id' => 636,
				'applicant_form_id'           => 1,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 30,
				'has_review'                  => false,
				'questionnaire_parent_applicant_field_id' => 579,
				'questionnaire_page_path' => '/form-citrus-heights-questionnaire/',
				'pdf_id'                  => '690f9d2e167ec',
				'features' => [],
			],

			'oakland' => [
				'label' => 'Oakland PD Questionnaire',
				'agency_id' => 642,
				'applicant_form_id'           => 1,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 31,
				'has_review'                  => false,
				'questionnaire_parent_applicant_field_id' => 579,
				'questionnaire_page_path' => '/form-oakland-questionnaire/',
				'pdf_id'                  => '690f9d2e167ec',
				'features' => [],
			],

			'wada' => [
				'label' => 'WADA Questionnaire',
				'agency_id' => 0,
				'applicant_form_id' => 0,
				'source_form_id' => 27,
				'review_form_id' => 28,
				'has_review' => true,
				'review_parent_questionnaire_field_id' => 1,
				'questionnaire_page_path' => '/form-wada-questionnaire/',
				'review_page_path'        => '/form-wada-review/',
				'pdf_id'                  => '6993b634ce4d2',
				'entry_mode' => 'kiosk',
				'features' => [
					'auto_scroll_radios' => true,
					'mark_all_as_no'     => true,
					'overlay_loader'     => true,
					'disable_gf_spinner' => true,
				],
			],
		];

		$workflows = apply_filters( 'pcpi_workflow_engine_workflows', $defaults );
		$workflows = is_array( $workflows ) ? $workflows : $defaults;

		self::validate_workflows( $workflows );

		return $workflows;
	}

	private static function validate_workflows( array $workflows ): void {

		foreach ( $workflows as $key => $wf ) {

			$prefix = "[PCPI WF VALIDATOR][$key] ";

			$required = [ 'label', 'source_form_id', 'questionnaire_page_path' ];

			foreach ( $required as $req ) {
				if ( empty( $wf[ $req ] ) ) {
					error_log( $prefix . "Missing required key: {$req}" );
				}
			}

			foreach ( [ 'applicant_form_id', 'source_form_id', 'review_form_id' ] as $form_key ) {
				if ( isset( $wf[ $form_key ] ) && $wf[ $form_key ] ) {
					if ( ! self::gf_form_exists( (int) $wf[ $form_key ] ) ) {
						error_log( $prefix . "Invalid form ID ({$form_key}): " . $wf[ $form_key ] );
					}
				}
			}

			if ( ! empty( $wf['has_review'] ) ) {
				if ( empty( $wf['review_form_id'] ) ) {
					error_log( $prefix . "has_review=true but review_form_id missing" );
				}
				if ( empty( $wf['review_page_path'] ) ) {
					error_log( $prefix . "has_review=true but review_page_path missing" );
				}
			}

			$uses_applicant = ! empty( $wf['applicant_form_id'] );

			if ( $uses_applicant ) {
				if ( empty( $wf['questionnaire_parent_applicant_field_id'] ) ) {
					error_log( $prefix . "Missing questionnaire_parent_applicant_field_id" );
				}
			} else {
				if ( ! empty( $wf['review_parent_applicant_field_id'] ) ) {
					error_log( $prefix . "Has review_parent_applicant_field_id but no applicant_form_id" );
				}
			}

			foreach ( [ 'questionnaire_page_path', 'review_page_path' ] as $path_key ) {
				if ( ! empty( $wf[ $path_key ] ) && strpos( $wf[ $path_key ], '/' ) !== 0 ) {
					error_log( $prefix . "{$path_key} should start with /" );
				}
			}

			if ( ! empty( $wf['pdf_id'] ) && ! is_string( $wf['pdf_id'] ) ) {
				error_log( $prefix . "pdf_id must be a string" );
			}

			if ( ! empty( $wf['entry_mode'] ) && ! in_array( $wf['entry_mode'], [ 'kiosk' ], true ) ) {
				error_log( $prefix . "Unknown entry_mode: " . $wf['entry_mode'] );
			}

			if ( isset( $wf['features'] ) && ! is_array( $wf['features'] ) ) {
				error_log( $prefix . "features must be an array" );
			}
		}
	}

	private static function gf_form_exists( int $form_id ): bool {

		if ( ! class_exists( 'GFAPI' ) ) {
			return true;
		}

		$form = \GFAPI::get_form( $form_id );

		return ! empty( $form );
	}
}