<?php
/**
 * PCPI Workflow Engine — Core Loader
 *
 * This class composes small traits:
 * - Registry (workflow definitions)
 * - Context (page + query-var parsing)
 * - Assets (enqueue + body classes)
 * - Shortcodes ([pcpi_questionnaire], [pcpi_review])
 * - Review (prefill + readonly + relationship hidden fields)
 * - Prefill (Applicant → Questionnaire + staff email)
 * - Merge Tags ({pcpi_questionnaire_link})
 *
 * Keeping behavior in traits makes this plugin easier to maintain as workflows scale.
 *
 * Debugging:
 * - Enable via filter (recommended):
 *   add_filter( 'pcpi_workflow_engine_debug', '__return_true' );
 *
 * - Logs go to PHP error log with prefix [PCPI WFE]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-registry.php';
require_once __DIR__ . '/trait-context.php';
require_once __DIR__ . '/trait-assets.php';
require_once __DIR__ . '/trait-shortcodes.php';
require_once __DIR__ . '/trait-review.php';
require_once __DIR__ . '/trait-prefill.php';
require_once __DIR__ . '/trait-merge-tags.php';
require_once __DIR__ . '/helpers-agency.php';

final class PCPI_Workflow_Engine {

	/**
	 * Default debug flag (enable via filter).
	 *
	 * NOTE: This constant is only a default. Prefer enabling via:
	 * add_filter( 'pcpi_workflow_engine_debug', '__return_true' );
	 */
	const DEBUG = false;

	use PCPI_WFE_Trait_Registry;
	use PCPI_WFE_Trait_Context;
	use PCPI_WFE_Trait_Assets;
	use PCPI_WFE_Trait_Shortcodes;
	use PCPI_WFE_Trait_Review;
	use PCPI_WFE_Trait_Prefill;
	use PCPI_WFE_Trait_Merge_Tags;

	/**
	 * Back-compat alias for older includes/tests.
	 * Prefer ::context().
	 *
	 * @return array<string,mixed>
	 */
	public static function context(): array {
		return self::get_context();
	}

	/**
	 * Public accessor for the workflow registry.
	 *
	 * Internally the registry lives in a private method so it can be enforced/validated.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_workflows(): array {
		return self::workflows();
	}

	/**
	 * Normalize workflow config so legacy/new keys both work.
	 *
	 * Your registry uses:
	 *  - source_form_id (Questionnaire)
	 *  - review_form_id (single)
	 *
	 * Newer code paths may expect:
	 *  - questionnaire_form_id
	 *  - review_form_ids (array)
	 *
	 * @param array<string,mixed> $wf
	 * @return array<string,mixed>
	 */
	private static function normalize_workflow( array $wf ): array {

		// Questionnaire form id (registry uses source_form_id).
		if ( empty( $wf['questionnaire_form_id'] ) && ! empty( $wf['source_form_id'] ) ) {
			$wf['questionnaire_form_id'] = absint( $wf['source_form_id'] );
		}

		// Review form ids array (registry uses review_form_id).
		if ( empty( $wf['review_form_ids'] ) || ! is_array( $wf['review_form_ids'] ) ) {
			if ( ! empty( $wf['review_form_id'] ) ) {
				$wf['review_form_ids'] = [ absint( $wf['review_form_id'] ) ];
			} else {
				$wf['review_form_ids'] = [];
			}
		} else {
			$wf['review_form_ids'] = array_values( array_unique( array_map( 'absint', $wf['review_form_ids'] ) ) );
		}

		return $wf;
	}

	/**
	 * Get a single workflow config (normalized).
	 *
	 * @param string $workflow_key
	 * @return array<string,mixed>
	 */
	public static function get_workflow( string $workflow_key ): array {
		$workflow_key = sanitize_key( $workflow_key );
		$workflows    = self::get_workflows();
		$wf           = isset( $workflows[ $workflow_key ] ) ? (array) $workflows[ $workflow_key ] : [];
		return self::normalize_workflow( $wf );
	}
	
	/**
 	* Determine the "root" entry id for a workflow.
	 *
	 * - Applicant-root workflows: root = applicant entry
	 * - Questionnaire-root workflows (kiosk/iPad): root = questionnaire entry
	 *
	 * @param array<string,mixed> $wf Normalized workflow config.
	 * @param array<string,mixed> $entry The entry we are currently dealing with.
	 * @return int
 	*/
	public static function get_root_entry_id( array $wf, array $entry ): int {

		$wf = self::normalize_workflow( $wf );

		$entry_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
		if ( $entry_id <= 0 ) {
			return 0;
		}

		// If there's no applicant_form_id configured, this workflow is questionnaire-rooted.
		if ( empty( $wf['applicant_form_id'] ) ) {
			return $entry_id;
		}

		// Applicant-root: the root is the applicant entry id (the entry passed in should be applicant in those contexts).
		return $entry_id;
	}

	/**
	 * Whether debug logging is enabled.
	 */
	public static function debug_enabled(): bool {
		/**
		 * Filter: enable Workflow Engine debug logging.
		 *
		 * Usage:
		 * add_filter( 'pcpi_workflow_engine_debug', '__return_true' );
		 */
		return (bool) apply_filters( 'pcpi_workflow_engine_debug', self::DEBUG );
	}

	/**
	 * Write a single debug line to the PHP error log when enabled.
	 *
	 * @param string              $message Short message.
	 * @param array<string,mixed> $context Optional structured data (JSON encoded).
	 */
	public static function debug_log( string $message, array $context = [] ): void {
		if ( ! self::debug_enabled() ) {
			return;
		}

		$line = '[PCPI WFE] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Compatibility logger.
	 *
	 * Some traits call self::log( $message, $context ). Older versions had log() as message-only.
	 * This wrapper keeps logging consistent and prevents PHP 8+ TypeErrors.
	 */
	public static function log( string $message, array $context = [] ): void {
		self::debug_log( $message, $context );
	}


	/**
	 * Validate workflow registry configuration.
 	*
 	* - Always validates shared keys used by Staff Dashboard / Review / PDF resolution.
 	* - Only requires applicant-root keys when applicant_form_id is present.
 	* - Logging respects pcpi_workflow_engine_debug (no noise in production).
 	*
 	* @param array<string,array<string,mixed>> $workflows
	 */
	private static function validate_workflows( array $workflows ): void {

		if ( ! self::debug_enabled() ) {
			return;
		}

		// Keys required for ALL workflows (including questionnaire-root / kiosk workflows)
		$required_base = [
			'source_form_id',
			'review_form_id',
			'review_parent_questionnaire_field_id',
			'review_parent_applicant_field_id',
		];

		// Keys required ONLY when the workflow is applicant-rooted (i.e., has an applicant_form_id)
		$required_applicant_root = [
			'applicant_workflow_field_id',
			'questionnaire_parent_applicant_field_id',
		];

		foreach ( $workflows as $key => $wf ) {

			$missing = [];
	
			// Base required keys
			foreach ( $required_base as $req ) {
				if ( empty( $wf[ $req ] ) ) {
					$missing[] = $req;
				}
			}

			// Applicant-root required keys (only when applicant_form_id is configured)
			if ( ! empty( $wf['applicant_form_id'] ) ) {
				if ( empty( $wf['applicant_form_id'] ) ) { // kept explicit for readability
					$missing[] = 'applicant_form_id';
				}
	
				foreach ( $required_applicant_root as $req ) {
					if ( empty( $wf[ $req ] ) ) {
						$missing[] = $req;
					}
				}
			} else {
				// Questionnaire-root workflow: warn if applicant-only keys are present (usually accidental)
				if ( ! empty( $wf['applicant_workflow_field_id'] ) || ! empty( $wf['questionnaire_parent_applicant_field_id'] ) ) {
					self::debug_log( 'workflow validation: questionnaire-root workflow has applicant-only keys set (check config)', [
						'workflow' => (string) $key,
						'applicant_workflow_field_id'            => isset( $wf['applicant_workflow_field_id'] ) ? $wf['applicant_workflow_field_id'] : null,
						'questionnaire_parent_applicant_field_id'=> isset( $wf['questionnaire_parent_applicant_field_id'] ) ? $wf['questionnaire_parent_applicant_field_id'] : null,
					] );
				}
			}

			if ( ! empty( $missing ) ) {
				self::debug_log( 'workflow validation: missing required keys', [
					'workflow' => (string) $key,
					'missing'  => $missing,
				] );
			}

			// PDF config sanity
			if ( empty( $wf['pdf_id'] ) && empty( $wf['pdf_ids'] ) ) {
				self::debug_log( 'workflow validation: missing pdf id', [
					'workflow' => (string) $key,
				] );
			}
		}
	}

	/**
	 * Get the human-friendly label for a workflow key.
	 *
	 * @param string $workflow_key
	 * @return string
	 */
	public static function get_workflow_label( string $workflow_key ): string {
		$workflow_key = sanitize_key( $workflow_key );
		$workflows    = self::get_workflows();
		$label        = isset( $workflows[ $workflow_key ]['label'] ) ? (string) $workflows[ $workflow_key ]['label'] : $workflow_key;

		/**
		 * Filter: pcpi_workflow_label
		 *
		 * Allows overriding the display label for a workflow key.
		 */
		$label = (string) apply_filters( 'pcpi_workflow_label', $label, $workflow_key );

		return $label !== '' ? $label : $workflow_key;
	}

	/**
	 * Build the Questionnaire URL for a given Applicant entry.
	 *
	 * This is the canonical builder used by merge tags and other plugins.
	 * Staff Dashboard (and notifications) should delegate to this.
	 *
	 * @param array<string,mixed> $applicant_entry Gravity Forms entry array (Applicant form).
	 * @return string Absolute URL, or empty string on failure.
	 */
	public static function build_questionnaire_url( array $applicant_entry ): string {
		$applicant_entry_id = isset( $applicant_entry['id'] ) ? absint( $applicant_entry['id'] ) : 0;
		if ( $applicant_entry_id <= 0 ) {
			self::debug_log( 'build_questionnaire_url: missing applicant entry id' );
			return '';
		}

		$workflow_key = self::resolve_workflow_key_from_applicant_entry( $applicant_entry );
		$wf           = self::get_workflow( $workflow_key );

		$path = isset( $wf['questionnaire_page_path'] ) ? (string) $wf['questionnaire_page_path'] : '/questionnaire/';
		if ( $path === '' ) {
			$path = '/questionnaire/';
		}

		$base_url = home_url( $path );

		$args = [
			'workflow'                  => $workflow_key,
			'parent_applicant_entry_id' => $applicant_entry_id,
		];

		$url = add_query_arg( $args, $base_url );

		/**
		 * Filter: pcpi_workflow_engine_questionnaire_url
		 *
		 * Allows other plugins (e.g. Site Access Control) to sign/transform the URL
		 * without Staff Dashboard duplicating logic.
		 *
		 * @param string              $url
		 * @param array<string,mixed> $applicant_entry
		 * @param string              $workflow_key
		 * @param array<string,mixed> $workflow
		 */
		$url = (string) apply_filters( 'pcpi_workflow_engine_questionnaire_url', $url, $applicant_entry, $workflow_key, $wf );

		return $url !== '' ? esc_url_raw( $url ) : '';
	}

	/**
	 * Cascade delete an Applicant entry and all related entries (Questionnaire → Review → nested child entries).
	 *
	 * @param int $applicant_entry_id
	 * @return array<string,int>|WP_Error
	 */
	public static function cascade_delete_applicant( int $applicant_entry_id ) {
		$applicant_entry_id = absint( $applicant_entry_id );
		if ( $applicant_entry_id <= 0 ) {
			return new WP_Error( 'pcpi_wfe_bad_arg', 'Missing applicant entry id.' );
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'pcpi_wfe_no_gf', 'Gravity Forms not available.' );
		}

		$applicant_entry = GFAPI::get_entry( $applicant_entry_id );
		if ( is_wp_error( $applicant_entry ) || empty( $applicant_entry ) ) {
			$details = '';
			if ( is_wp_error( $applicant_entry ) ) {
				$details = $applicant_entry->get_error_code() . ': ' . $applicant_entry->get_error_message();
			} else {
				$details = 'empty entry result';
			}
			error_log( '[PCPI WFE] cascade_delete_applicant: applicant entry not found id=' . $applicant_entry_id . ' details=' . $details ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'pcpi_wfe_applicant_missing', 'Applicant entry not found (ID ' . $applicant_entry_id . ').' );
		}

		$workflow_key = self::resolve_workflow_key_from_applicant_entry( $applicant_entry );
		$wf           = self::get_workflow( $workflow_key );

		$applicant_form_id     = isset( $wf['applicant_form_id'] ) ? absint( $wf['applicant_form_id'] ) : 0;
		$questionnaire_form_id = isset( $wf['questionnaire_form_id'] ) ? absint( $wf['questionnaire_form_id'] ) : 0;
		$review_form_ids       = ( ! empty( $wf['review_form_ids'] ) && is_array( $wf['review_form_ids'] ) )
			? array_values( array_unique( array_map( 'absint', $wf['review_form_ids'] ) ) )
			: [];

		$entry_form_id = absint( $applicant_entry['form_id'] ?? 0 );
		if ( $applicant_form_id > 0 && $entry_form_id !== $applicant_form_id ) {
			$del = GFAPI::delete_entry( $applicant_entry_id );
			if ( is_wp_error( $del ) ) {
				return $del;
			}
			return [ 'deleted_parent' => 1, 'deleted_questionnaire' => 0, 'deleted_review' => 0, 'deleted_nested' => 0 ];
		}

		$q_parent_applicant_fid = isset( $wf['questionnaire_parent_applicant_field_id'] ) ? absint( $wf['questionnaire_parent_applicant_field_id'] ) : 0;
		$review_parent_q_fid    = isset( $wf['review_parent_questionnaire_field_id'] ) ? absint( $wf['review_parent_questionnaire_field_id'] ) : 0;
		$review_parent_a_fid    = isset( $wf['review_parent_applicant_field_id'] ) ? absint( $wf['review_parent_applicant_field_id'] ) : 0;

		if ( $questionnaire_form_id > 0 && $q_parent_applicant_fid <= 0 ) {
			$det = self::detect_hidden_field_id(
				$questionnaire_form_id,
				[ 'parent_applicant_entry_id', 'parent_applicant_entry', 'parent_applicant_eid' ],
				[ 'parent applicant', 'parent applicant entry' ]
			);
			$q_parent_applicant_fid = $det > 0 ? $det : 0;
		}

		if ( $review_parent_q_fid <= 0 || $review_parent_a_fid <= 0 ) {
			foreach ( $review_form_ids as $rid ) {
				if ( $review_parent_q_fid <= 0 ) {
					$det = self::detect_hidden_field_id(
						$rid,
						[ 'parent_questionnaire_entry_id', 'parent_questionnaire_entry', 'parent_questionnaire_eid' ],
						[ 'parent questionnaire', 'parent questionnaire entry' ]
					);
					$review_parent_q_fid = $det > 0 ? $det : $review_parent_q_fid;
				}
				if ( $review_parent_a_fid <= 0 ) {
					$det = self::detect_hidden_field_id(
						$rid,
						[ 'parent_applicant_entry_id', 'parent_applicant_entry', 'parent_applicant_eid' ],
						[ 'parent applicant', 'parent applicant entry' ]
					);
					$review_parent_a_fid = $det > 0 ? $det : $review_parent_a_fid;
				}
			}
		}

		self::debug_log( 'cascade_delete_applicant: resolved', [
			'applicant_entry_id'     => $applicant_entry_id,
			'workflow'               => $workflow_key,
			'applicant_form_id'      => $applicant_form_id,
			'questionnaire_form_id'  => $questionnaire_form_id,
			'review_form_ids'        => $review_form_ids,
			'q_parent_applicant_fid' => $q_parent_applicant_fid,
			'review_parent_q_fid'    => $review_parent_q_fid,
			'review_parent_a_fid'    => $review_parent_a_fid,
			'entry_form_id'          => $entry_form_id,
		] );

		$deleted_parent        = 0;
		$deleted_questionnaire = 0;
		$deleted_review        = 0;
		$deleted_nested        = 0;

		$q_entries = [];
		if ( $questionnaire_form_id > 0 && $q_parent_applicant_fid > 0 ) {
			$q_entries = self::get_entries_paged(
				$questionnaire_form_id,
				[
					'status'        => 'active',
					'field_filters' => [
						[ 'key' => (string) $q_parent_applicant_fid, 'value' => (string) $applicant_entry_id ],
					],
				]
			);
			if ( is_wp_error( $q_entries ) ) {
				return $q_entries;
			}
		}

		self::debug_log( 'cascade_delete_applicant: questionnaires found', [
			'applicant_entry_id'     => $applicant_entry_id,
			'questionnaire_form_id'  => $questionnaire_form_id,
			'q_parent_applicant_fid' => $q_parent_applicant_fid,
			'count'                  => is_array( $q_entries ) ? count( $q_entries ) : 0,
		] );

		if ( empty( $q_entries ) && $questionnaire_form_id > 0 ) {
			self::debug_log( 'cascade_delete_applicant: questionnaire hidden fields', [
				'questionnaire_form_id' => $questionnaire_form_id,
				'hidden_fields'         => self::describe_hidden_fields( $questionnaire_form_id ),
			] );
		}

		foreach ( $q_entries as $q ) {
			$q_entry_id = absint( $q['id'] ?? 0 );
			if ( $q_entry_id <= 0 ) {
				continue;
			}

			$review_entries = [];
			if ( $review_parent_q_fid > 0 ) {
				foreach ( $review_form_ids as $rid ) {
					$tmp = self::get_entries_paged(
						$rid,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $review_parent_q_fid, 'value' => (string) $q_entry_id ],
							],
						]
					);
					if ( is_wp_error( $tmp ) ) {
						return $tmp;
					}
					if ( ! empty( $tmp ) ) {
						$review_entries = $tmp;
						break;
					}
				}
			}

			if ( empty( $review_entries ) && $review_parent_a_fid > 0 ) {
				foreach ( $review_form_ids as $rid ) {
					$tmp = self::get_entries_paged(
						$rid,
						[
							'status'        => 'active',
							'field_filters' => [
								[ 'key' => (string) $review_parent_a_fid, 'value' => (string) $applicant_entry_id ],
							],
						]
					);
					if ( is_wp_error( $tmp ) ) {
						return $tmp;
					}
					if ( ! empty( $tmp ) ) {
						$review_entries = $tmp;
						break;
					}
				}
			}

			foreach ( $review_entries as $re ) {
				$rid = absint( $re['id'] ?? 0 );
				if ( $rid <= 0 ) {
					continue;
				}
				$del = GFAPI::delete_entry( $rid );
				if ( is_wp_error( $del ) ) {
					return $del;
				}
				$deleted_review++;
			}

			$deleted_nested += self::delete_nested_children_for_entry( $questionnaire_form_id, $q );

			$del_q = GFAPI::delete_entry( $q_entry_id );
			if ( is_wp_error( $del_q ) ) {
				return $del_q;
			}
			$deleted_questionnaire++;
		}

		$del_parent = GFAPI::delete_entry( $applicant_entry_id );
		if ( is_wp_error( $del_parent ) ) {
			return $del_parent;
		}
		$deleted_parent = 1;

		self::debug_log( 'cascade_delete_applicant: done', [
			'applicant' => $applicant_entry_id,
			'workflow'  => $workflow_key,
			'del_q'     => $deleted_questionnaire,
			'del_r'     => $deleted_review,
			'del_n'     => $deleted_nested,
		] );

		return [
			'deleted_parent'        => $deleted_parent,
			'deleted_questionnaire' => $deleted_questionnaire,
			'deleted_review'        => $deleted_review,
			'deleted_nested'        => $deleted_nested,
		];
	}

	/**
	 * Resolve workflow key from an Applicant entry.
	 *
	 * @param array<string,mixed> $applicant_entry
	 */
	private static function resolve_workflow_key_from_applicant_entry( array $applicant_entry ): string {
		$workflows = self::get_workflows();
		foreach ( $workflows as $key => $wf ) {
			if ( empty( $wf['applicant_workflow_field_id'] ) ) {
				continue;
			}
			$fid = (string) absint( $wf['applicant_workflow_field_id'] );
			$raw = isset( $applicant_entry[ $fid ] ) ? (string) $applicant_entry[ $fid ] : '';
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

	/**
	 * Paged GF entry retrieval helper.
	 *
	 * @param int                 $form_id
	 * @param array<string,mixed> $search
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function get_entries_paged( int $form_id, array $search ) {
		$form_id = absint( $form_id );
		if ( $form_id <= 0 ) {
			return [];
		}
		$page_size = 200;
		$offset    = 0;
		$out       = [];
		$total     = 0;

		do {
			$paging = [ 'offset' => $offset, 'page_size' => $page_size ];
			$tmp    = GFAPI::get_entries( $form_id, $search, null, $paging, $total );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			if ( empty( $tmp ) ) {
				break;
			}
			$out    = array_merge( $out, $tmp );
			$offset += $page_size;
		} while ( $offset < (int) $total );

		return $out;
	}

	/**
	 * Attempt to detect a hidden field ID by inputName (parameter name) or label.
	 */
	private static function detect_hidden_field_id( int $form_id, array $input_names, array $label_fragments ): int {
		$form_id = absint( $form_id );
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return 0;
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || empty( $form['fields'] ) ) {
			return 0;
		}

		$input_names     = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $input_names ) ) ) );
		$label_fragments = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $label_fragments ) ) ) );

		foreach ( $form['fields'] as $field ) {
			if ( empty( $field ) || empty( $field->id ) ) {
				continue;
			}
			$type = isset( $field->type ) ? (string) $field->type : '';
			if ( $type !== 'hidden' ) {
				continue;
			}

			$input_name = '';
			if ( isset( $field->inputName ) ) {
				$input_name = strtolower( (string) $field->inputName );
			}
			if ( $input_name !== '' && in_array( $input_name, $input_names, true ) ) {
				return absint( $field->id );
			}

			$label = strtolower( (string) ( $field->adminLabel ?: $field->label ) );
			foreach ( $label_fragments as $frag ) {
				if ( $frag !== '' && strpos( $label, $frag ) !== false ) {
					return absint( $field->id );
				}
			}
		}

		return 0;
	}

	/**
	 * Describe all hidden fields on a form (for debugging relationship-field issues).
	 *
	 * @param int $form_id
	 * @return array<int,array<string,string|int>>
	 */
	private static function describe_hidden_fields( int $form_id ): array {
		$form_id = absint( $form_id );
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || empty( $form['fields'] ) ) {
			return [];
		}

		$out = [];
		foreach ( $form['fields'] as $field ) {
			if ( empty( $field ) || empty( $field->id ) ) {
				continue;
			}
			$type = isset( $field->type ) ? (string) $field->type : '';
			if ( $type !== 'hidden' ) {
				continue;
			}

			$out[] = [
				'id'         => absint( $field->id ),
				'inputName'  => isset( $field->inputName ) ? (string) $field->inputName : '',
				'adminLabel' => isset( $field->adminLabel ) ? (string) $field->adminLabel : '',
				'label'      => isset( $field->label ) ? (string) $field->label : '',
			];
		}

		return $out;
	}

	/**
	 * Delete GP Nested Forms child entries referenced by a parent entry.
	 *
	 * @param int                 $form_id Parent form id.
	 * @param array<string,mixed> $entry   Parent entry array.
	 * @return int Count of deleted child entries.
	 */
	private static function delete_nested_children_for_entry( int $form_id, array $entry ): int {
		$form_id = absint( $form_id );
		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return 0;
		}

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) || empty( $form['fields'] ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $form['fields'] as $field ) {
			if ( empty( $field ) || empty( $field->id ) ) {
				continue;
			}

			// GP Nested Forms uses type "form".
			if ( ! isset( $field->type ) || (string) $field->type !== 'form' ) {
				continue;
			}

			$k   = (string) absint( $field->id );
			$raw = isset( $entry[ $k ] ) ? (string) $entry[ $k ] : '';
			if ( $raw === '' ) {
				continue;
			}

			preg_match_all( '/\d+/', $raw, $m );
			$ids = isset( $m[0] ) ? array_values( array_unique( array_map( 'absint', $m[0] ) ) ) : [];
			foreach ( $ids as $cid ) {
				if ( $cid <= 0 ) {
					continue;
				}
				$del = GFAPI::delete_entry( $cid );
				if ( ! is_wp_error( $del ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	public static function init(): void {

		self::debug_log( 'init() called', [
			'wp'   => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 1 : 0,
			'ajax' => ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 1 : 0,
			'uri'  => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
		] );

		// Validate workflow definitions early (non-fatal, debug-only).
		add_action( 'init', function () {
			self::validate_workflows( self::get_workflows() );
		}, 5 );

		// Shortcodes
		add_action( 'init', [ __CLASS__, 'register_shortcodes' ] );

		// Merge tags (Applicant notifications, confirmations, etc.)
		add_filter( 'gform_custom_merge_tags', [ __CLASS__, 'register_custom_merge_tags' ], 10, 4 );
		add_filter( 'gform_replace_merge_tags', [ __CLASS__, 'replace_custom_merge_tags' ], 10, 7 );

		// Optional: log once after shortcodes register (low-noise, helps confirm load order).
		add_action( 'init', function () {
			self::debug_log( 'wp init action fired' );
		}, 99 );

		// Body class + assets
		add_filter( 'body_class', [ __CLASS__, 'maybe_add_body_classes' ], 20 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ], 20 );

		// Review-mode (source form prefill + readonly)
		add_filter( 'gform_pre_render', [ __CLASS__, 'maybe_prefill_review_source_form' ], 1 );
		add_filter( 'gform_pre_validation', [ __CLASS__, 'maybe_prefill_review_source_form' ], 1 );

		add_filter( 'gform_field_content', [ __CLASS__, 'maybe_force_readonly_markup' ], 10, 5 );
		add_filter( 'gform_submit_button', [ __CLASS__, 'maybe_hide_source_submit' ], 10, 2 );

		add_filter( 'gform_pre_render', [ __CLASS__, 'maybe_prefill_review_hidden_fields' ], 5 );
		add_filter( 'gform_pre_validation', [ __CLASS__, 'maybe_prefill_review_hidden_fields' ], 5 );
		
		// Review-mode UI controls (workflow-aware).
		add_filter( 'gform_savecontinue_link', [ __CLASS__, 'maybe_hide_save_continue_on_review' ], 10, 2 );

		// Applicant → Questionnaire prefill + staff email (questionnaire render)
		add_filter( 'gform_pre_render', [ __CLASS__, 'maybe_prefill_questionnaire_from_applicant' ], 5 );
		add_filter( 'gform_pre_validation', [ __CLASS__, 'maybe_prefill_questionnaire_from_applicant' ], 5 );
		add_filter( 'gform_pre_submission_filter', [ __CLASS__, 'maybe_prefill_questionnaire_from_applicant' ], 5 );

		add_filter( 'gform_pre_render', [ __CLASS__, 'maybe_populate_staff_email' ], 8 );
		add_filter( 'gform_pre_validation', [ __CLASS__, 'maybe_populate_staff_email' ], 8 );
		add_filter( 'gform_pre_submission_filter', [ __CLASS__, 'maybe_populate_staff_email' ], 8 );
		
	}

	/**
	 * Check whether a review entry exists for a questionnaire entry.
	 *
	 * Canonical signal for "review complete" in the new engine.
	 *
	 * @param int $questionnaire_entry_id
	 * @return bool
	 */
	public static function has_review_for_questionnaire( int $questionnaire_entry_id ): bool {

		if ( ! $questionnaire_entry_id || ! class_exists( 'GFAPI' ) ) {
			return false;
		}

		// Find the active workflow (context-aware).
		$ctx = self::context();
		$wf  = (array) ( $ctx['workflow'] ?? [] );

		$review_form_id = (int) ( $wf['review_form_id'] ?? 0 );
		if ( ! $review_form_id ) {
			return false;
		}

		$parent_q_field_id = (int) ( $wf['review_parent_questionnaire_field_id'] ?? 0 );
		if ( ! $parent_q_field_id ) {
			return false;
		}

		$search_criteria = [
			'status'        => 'active',
			'field_filters' => [
				[
					'key'   => (string) $parent_q_field_id,
					'value' => (string) $questionnaire_entry_id,
				],
			],
		];

		$entries = GFAPI::get_entries( $review_form_id, $search_criteria, null, [ 'page_size' => 1 ] );

		return ! is_wp_error( $entries ) && ! empty( $entries );
	}
}
