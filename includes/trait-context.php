<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Context {

	const DEFAULT_REVIEW_PAGE_SLUG        = 'review';
	const DEFAULT_QUESTIONNAIRE_PAGE_SLUG = 'questionnaire';

	// Query vars
	const QS_WORKFLOW             = 'workflow';
	const QS_ENTRY_ID             = 'entry_id';
	const QS_Q_ENTRY_ID           = 'q_entry_id'; // ✅ legacy/back-compat alias for source entry id
	const QS_PARENT_APPLICANT_EID = 'parent_applicant_entry_id';

	private static array $ctx = [];

	private static function review_page_slug(): string {
		return (string) apply_filters( 'pcpi_workflow_engine_review_page_slug', self::DEFAULT_REVIEW_PAGE_SLUG );
	}

	private static function questionnaire_page_slug(): string {
		return (string) apply_filters( 'pcpi_workflow_engine_questionnaire_page_slug', self::DEFAULT_QUESTIONNAIRE_PAGE_SLUG );
	}

	/**
	 * Enable logs:
	 * add_filter('pcpi_workflow_engine_debug', '__return_true');
	 */
	private static function debug_enabled(): bool {
		return (bool) apply_filters( 'pcpi_workflow_engine_debug', self::DEBUG );
	}

	private static function log( string $msg ): void {
		if ( self::debug_enabled() ) {
			error_log( '[PCPI Workflow Engine] ' . $msg );
		}
	}

	/**
	 * Normalize a path like "questionnaire" or "/questionnaire" to "/questionnaire/".
	 */
	private static function norm_path( string $path ): string {
		$path = '/' . ltrim( trim( $path ), '/' );
		return trailingslashit( $path );
	}

	/**
	 * Get current request path as "/something/" (no domain, no query string).
	 */
	private static function current_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return self::norm_path( $path );
	}

	/**
	 * If you hit /questionnaire/?parent_applicant_entry_id=123 WITHOUT workflow,
	 * infer workflow from the Applicant entry's dropdown value.
	 */
	private static function infer_workflow_from_applicant_entry( int $parent_applicant_eid, array $workflows ): string {

		if ( ! $parent_applicant_eid || ! class_exists( 'GFAPI' ) ) {
			return '';
		}

		$app_entry = GFAPI::get_entry( $parent_applicant_eid );
		if ( is_wp_error( $app_entry ) || empty( $app_entry['id'] ) ) {
			return '';
		}

		$app_form_id = (int) rgar( $app_entry, 'form_id' );

		foreach ( $workflows as $key => $wf ) {

			$wf = (array) $wf;

			if ( empty( $wf['applicant_form_id'] ) || (int) $wf['applicant_form_id'] !== $app_form_id ) {
				continue;
			}

			$field_id = (int) ( $wf['applicant_workflow_field_id'] ?? 0 );
			if ( ! $field_id ) {
				continue;
			}

			$raw = (string) rgar( $app_entry, (string) $field_id );
			$val = sanitize_key( $raw );

			// Dropdown VALUE should be the workflow key.
			if ( $val && isset( $workflows[ $val ] ) ) {
				return $val;
			}

			// If "Show Values" wasn't enabled, try matching labels.
			if ( $val ) {
				foreach ( $workflows as $k2 => $wf2 ) {
					$label = sanitize_key( (string) ( $wf2['label'] ?? '' ) );
					if ( $label && $label === $val ) {
						return (string) $k2;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Resolve whether the current page is a questionnaire/review page for ANY workflow.
	 *
	 * If a workflow key is present in the URL, we prefer that workflow's page paths.
	 * Otherwise we attempt to match by request path.
	 *
	 * @param array<string,array<string,mixed>> $workflows
	 * @param string $workflow_key
	 * @return array{is_questionnaire:bool,is_review:bool,workflow_key:string}
	 */
	private static function resolve_mode_and_workflow( array $workflows, string $workflow_key ): array {

		$workflow_key = sanitize_key( $workflow_key );
		$req_path     = self::current_request_path();

		// Start with the default WordPress slug checks (back-compat).
		$is_review_page        = function_exists( 'is_page' ) && is_page( self::review_page_slug() );
		$is_questionnaire_page = function_exists( 'is_page' ) && is_page( self::questionnaire_page_slug() );

		// If we have a workflow key and it exists, use its configured paths (by URL path match).
		if ( $workflow_key && isset( $workflows[ $workflow_key ] ) ) {
			$wf = (array) $workflows[ $workflow_key ];

			$q_path = self::norm_path( (string) ( $wf['questionnaire_page_path'] ?? '' ) );
			$r_path = self::norm_path( (string) ( $wf['review_page_path'] ?? '' ) );

			if ( $q_path !== '//' && $req_path === $q_path ) {
				$is_questionnaire_page = true;
			}
			if ( $r_path !== '//' && $req_path === $r_path ) {
				$is_review_page = true;
			}
		}

		// If still not identified, attempt to match ANY workflow path and infer workflow key.
		if ( ! $is_questionnaire_page && ! $is_review_page ) {
			foreach ( $workflows as $k => $wf ) {
				$wf = (array) $wf;

				$q_path = self::norm_path( (string) ( $wf['questionnaire_page_path'] ?? '' ) );
				$r_path = self::norm_path( (string) ( $wf['review_page_path'] ?? '' ) );

				if ( $q_path !== '//' && $req_path === $q_path ) {
					$is_questionnaire_page = true;
					if ( ! $workflow_key ) {
						$workflow_key = (string) $k;
					}
					break;
				}

				if ( $r_path !== '//' && $req_path === $r_path ) {
					$is_review_page = true;
					if ( ! $workflow_key ) {
						$workflow_key = (string) $k;
					}
					break;
				}
			}
		}

		return [
			'is_questionnaire' => (bool) $is_questionnaire_page,
			'is_review'        => (bool) $is_review_page,
			'workflow_key'     => sanitize_key( $workflow_key ),
		];
	}

	private static function get_context(): array {

		if ( ! empty( self::$ctx ) ) {
			return self::$ctx;
		}

		$workflow_key = isset( $_GET[ self::QS_WORKFLOW ] ) ? sanitize_key( (string) $_GET[ self::QS_WORKFLOW ] ) : '';
		$entry_id     = isset( $_GET[ self::QS_ENTRY_ID ] ) ? absint( $_GET[ self::QS_ENTRY_ID ] ) : 0;

		// ✅ legacy/back-compat: q_entry_id is intended to be the SOURCE (questionnaire) entry id
		$q_entry_id   = isset( $_GET[ self::QS_Q_ENTRY_ID ] ) ? absint( $_GET[ self::QS_Q_ENTRY_ID ] ) : 0;

		$parent_applicant_eid = isset( $_GET[ self::QS_PARENT_APPLICANT_EID ] ) ? absint( $_GET[ self::QS_PARENT_APPLICANT_EID ] ) : 0;

		$workflows = self::workflows();

		// Determine page mode + possibly infer workflow from page path.
		$mode = self::resolve_mode_and_workflow( $workflows, $workflow_key );
		$is_review_page        = (bool) $mode['is_review'];
		$is_questionnaire_page = (bool) $mode['is_questionnaire'];
		$workflow_key          = (string) $mode['workflow_key'];

		// On review page, prefer q_entry_id when present (prevents empty readonly source form)
		if ( $is_review_page && $q_entry_id ) {
			if ( $entry_id && $entry_id !== $q_entry_id ) {
				self::log( "Review context: overriding entry_id={$entry_id} with q_entry_id={$q_entry_id}" );
			}
			$entry_id = $q_entry_id;
		}

		// Auto-infer workflow on questionnaire page when not provided.
		if ( $is_questionnaire_page && ! $workflow_key && $parent_applicant_eid ) {
			$inferred = self::infer_workflow_from_applicant_entry( $parent_applicant_eid, $workflows );
			if ( $inferred ) {
				$workflow_key = $inferred;
				self::log( "Inferred workflow={$workflow_key} from applicant_entry_id={$parent_applicant_eid}" );
			}
		}

		$workflow = ( $workflow_key && isset( $workflows[ $workflow_key ] ) ) ? (array) $workflows[ $workflow_key ] : [];

		self::$ctx = [
			'is_review_page'        => $is_review_page,
			'is_questionnaire_page' => $is_questionnaire_page,
			'workflow_key'          => $workflow_key,
			'workflow'              => $workflow,
			'entry_id'              => $entry_id,
			'q_entry_id'            => $q_entry_id,
			'parent_applicant_entry_id' => $parent_applicant_eid,
		];

		return self::$ctx;
	}
}
