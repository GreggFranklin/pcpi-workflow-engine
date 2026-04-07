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
	 *   'label' => 'Example Workflow',
	 *   'agency_id' => 123,
	 *
	 *   // 0 when NOT using Applicant form (kiosk/direct workflows)
	 *   'applicant_form_id' => 1,
	 *
	 *   'applicant_workflow_field_id' => 1005,
	 *
	 *   'source_form_id' => 2,
	 *   'review_form_id' => 26,
	 *   'has_review' => true,
	 *
	 *   // Only required if applicant_form_id > 0
	 *   'questionnaire_parent_applicant_field_id' => 579,
	 *
	 *   'review_parent_questionnaire_field_id' => 1,
	 *
	 *   // Only required if applicant_form_id > 0
	 *   'review_parent_applicant_field_id' => 3,
	 *
	 *   'questionnaire_page_path' => '/form-example-questionnaire/',
	 *   'review_page_path'        => '/form-example-review/',
	 *
	 *   'pdf_id' => 'abc123',
	 *
	 *   // 'kiosk' = direct access, no applicant record
	 *   'entry_mode' => 'kiosk',
	 *
	 *   'features' => [],
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
				'applicant_form_id'           => 0,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 24,
				'review_form_id'              => 25,
				'has_review'                  => true,
				'questionnaire_parent_applicant_field_id' => 62,
				'review_parent_questionnaire_field_id'    => 1,
				'review_parent_applicant_field_id'        => 3,
				'questionnaire_page_path' => '/form-vast-questionnaire/',
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
				'pdf_id'                  => '69d02f55d84c1',
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

	/**
	 * ------------------------------------------------------------------------
	 * LOGGER (UPGRADED)
	 * ------------------------------------------------------------------------
	 */
	private static function log( string $message, string $level = 'INFO' ): void {

		if ( ! apply_filters( 'pcpi_workflow_engine_debug', false ) ) {
			return;
		}

		$time = date( 'Y-m-d H:i:s' );

		error_log( "[PCPI][$level][$time] $message" );
	}

	/**
	 * ------------------------------------------------------------------------
	 * VALIDATOR
	 * ------------------------------------------------------------------------
	 */
	private static function validate_workflows( array $workflows ): void {

		foreach ( $workflows as $key => $wf ) {

			$prefix = "[PCPI WF VALIDATOR][$key] ";

			$is_kiosk = isset( $wf['entry_mode'] ) && $wf['entry_mode'] === 'kiosk';
			$uses_applicant = ! empty( $wf['applicant_form_id'] );

			// REQUIRED
			foreach ( [ 'label', 'source_form_id', 'questionnaire_page_path' ] as $req ) {
				if ( empty( $wf[ $req ] ) ) {
					self::log( $prefix . "Missing required key: {$req}", 'ERROR' );
				}
			}

			// FORM VALIDATION
			foreach ( [ 'applicant_form_id', 'source_form_id', 'review_form_id' ] as $form_key ) {
				if ( isset( $wf[ $form_key ] ) && $wf[ $form_key ] ) {
					if ( ! self::gf_form_exists( (int) $wf[ $form_key ] ) ) {
						self::log( $prefix . "Invalid form ID ({$form_key}): " . $wf[ $form_key ], 'ERROR' );
					}
				}
			}

			// REVIEW
			if ( ! empty( $wf['has_review'] ) ) {
				if ( empty( $wf['review_form_id'] ) ) {
					self::log( $prefix . "has_review=true but review_form_id missing", 'ERROR' );
				}
				if ( empty( $wf['review_page_path'] ) ) {
					self::log( $prefix . "has_review=true but review_page_path missing", 'ERROR' );
				}
			}

			// APPLICANT RULES
			if ( ! $is_kiosk && $uses_applicant ) {
				if ( empty( $wf['questionnaire_parent_applicant_field_id'] ) ) {
					self::log( $prefix . "Missing questionnaire_parent_applicant_field_id", 'ERROR' );
				}
			}

			if ( $is_kiosk && $uses_applicant ) {
				self::log( $prefix . "Kiosk workflow should not use applicant_form_id", 'ERROR' );
			}

			// PATHS
			foreach ( [ 'questionnaire_page_path', 'review_page_path' ] as $path_key ) {
				if ( ! empty( $wf[ $path_key ] ) && strpos( $wf[ $path_key ], '/' ) !== 0 ) {
					self::log( $prefix . "{$path_key} should start with /", 'ERROR' );
				}
			}

			// PDF
			if ( ! empty( $wf['pdf_id'] ) && ! is_string( $wf['pdf_id'] ) ) {
				self::log( $prefix . "pdf_id must be a string", 'ERROR' );
			}

			// ENTRY MODE
			if ( ! empty( $wf['entry_mode'] ) && ! in_array( $wf['entry_mode'], [ 'kiosk' ], true ) ) {
				self::log( $prefix . "Unknown entry_mode: " . $wf['entry_mode'], 'ERROR' );
			}

			// FEATURES
			if ( isset( $wf['features'] ) && ! is_array( $wf['features'] ) ) {
				self::log( $prefix . "features must be an array", 'ERROR' );
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