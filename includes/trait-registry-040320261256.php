<?php
/**
 * IMPORTANT:
 * Before adding or editing workflows, read:
 * docs/workflows.md
 *
 * Every workflow is a contract.
 * Changes here affect Staff Dashboard, PDFs, and access control.
 *
 * ------------------------------------------------------------------------
 * PREFILL CONTRACT (Applicant -> Questionnaire)
 * ------------------------------------------------------------------------
 * The Workflow Engine can prefill Applicant name/email (and other shared data)
 * into the selected Questionnaire form when the applicant clicks the emailed link.
 *
 * How it works:
 * - Prefill is driven by matching field Admin Labels between:
 *     Applicant Form (applicant_form_id)
 *   and
 *     Questionnaire Form (source_form_id)
 *
 * REQUIRED:
 * - The Questionnaire form MUST include identity fields (Name, Email, etc.)
 * - Those identity fields MUST use the SAME Admin Labels as the Applicant form
 *
 * Example (recommended canonical Admin Labels):
 * - applicant_first_name
 * - applicant_last_name
 * - applicant_email
 *
 * If Admin Labels do not match, prefill will be skipped.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Registry {

	private static function workflows(): array {

		$defaults = [
			'polygraph' => [
				'label' => 'Polygraph Questionnaire',
				'agency_id' => 641,
				'applicant_form_id'            => 1,
				'applicant_workflow_field_id'  => 1005,
				'source_form_id'               => 2,  // Questionnaire
				'review_form_id'               => 26, // Review
				
				'has_review' => true,

				'questionnaire_parent_applicant_field_id' => 579,
				'review_parent_questionnaire_field_id'    => 1,
				'review_parent_applicant_field_id'        => 3,

				'questionnaire_page_path' => '/form-polygraph-questionnaire/', // or workflow-specific
				'review_page_path'        => '/form-polygraph-questionnaire-review/',
				'pdf_id'                  => '690f9d2e167ec',

				'features' => [
					// Intentionally empty for now.
				],
			],

			'vast' => [
				'label' => 'VAST - Law Enforcement Questionnaire',
				'agency_id' => 641,
				'applicant_form_id'           => 0,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 24,
				'review_form_id'              => 25,
				
				'has_review' => true,

				'questionnaire_parent_applicant_field_id' => 62,
				'review_parent_questionnaire_field_id'    => 1,
				'review_parent_applicant_field_id'        => 3,

				'questionnaire_page_path' => '/form-vast-questionnaire/', // or workflow-specific
				'review_page_path'        => '/form-vast-questionnaire-review/',
				'pdf_id'                  => '698652e8ec09d',
				
				'entry_mode' => 'kiosk',

				'features' => [
					'auto_scroll_radios' => true,

					// Prepared for future registry-driven UX wiring:
					'mark_all_as_no'     => true, // section/form bulk toggle helper

					// Prepared for future loader wiring:
					'overlay_loader'     => true, // kiosk paging overlay
					'disable_gf_spinner' => true, // suppress GF default spinner (if/when wired)
				],
			],
			
			'citrus_heights' => [
				'label' => 'Citrus Heights PD Questionnaire',
				'agency_id' => 636, // CPT POST ID
				'applicant_form_id'           => 1,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 30,
				// 'review_form_id'              => 25,
				
				'has_review' => false,

				'questionnaire_parent_applicant_field_id' => 579,
				//'review_parent_questionnaire_field_id'    => 1,
				//'review_parent_applicant_field_id'        => 3,

				'questionnaire_page_path' => '/form-citrus-heights-questionnaire/', // or workflow-specific
				//'review_page_path'        => '/form-vast-questionnaire-review/',
				'pdf_id'                  => '690f9d2e167ec',
				
				//'entry_mode' => 'kiosk',

				'features' => [
					// Intentionally empty for now.
				],
			],
			
			'oakland' => [
				'label' => 'Oakland PD Questionnaire',
				'agency_id' => 642, // CPT POST ID
				'applicant_form_id'           => 1,
				'applicant_workflow_field_id' => 1005,
				'source_form_id'              => 31,
				// 'review_form_id'              => 25,
				
				'has_review' => false,

				'questionnaire_parent_applicant_field_id' => 579,
				//'review_parent_questionnaire_field_id'    => 1,
				//'review_parent_applicant_field_id'        => 3,

				'questionnaire_page_path' => '/form-oakland-questionnaire/', // or workflow-specific
				//'review_page_path'        => '/form-vast-questionnaire-review/',
				'pdf_id'                  => '690f9d2e167ec',
				
				//'entry_mode' => 'kiosk',

				'features' => [
					// Intentionally empty for now.
				],
			],

			'wada' => [
				'label' => 'WADA Questionnaire',
				'agency_id' => 0, // CPT POST ID
				'applicant_form_id' => 0, // Does not use an application
				'source_form_id' => 27,
				'review_form_id' => 28,
				
				'has_review' => true,

				'review_parent_questionnaire_field_id' => 1,
				'review_parent_applicant_field_id'     => 3,

				'questionnaire_page_path' => '/form-wada-questionnaire/',
				'review_page_path'        => '/form-wada-review/',
				'pdf_id'                  => '6993b634ce4d2',

				'entry_mode' => 'kiosk',

				'features' => [

					'auto_scroll_radios' => true,

					// Prepared for future registry-driven UX wiring:
					'mark_all_as_no'     => true, // section/form bulk toggle helper

					// Prepared for future loader wiring:
					'overlay_loader'     => true, // kiosk paging overlay
					'disable_gf_spinner' => true, // suppress GF default spinner (if/when wired)
				],
			],
		];

		$workflows = apply_filters( 'pcpi_workflow_engine_workflows', $defaults );

		return is_array( $workflows ) ? $workflows : $defaults;
	}
}