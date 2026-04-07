<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Shortcodes {

	const SHORTCODE_REVIEW        = 'pcpi_review';
	const SHORTCODE_REVIEW_LEGACY = 'pcpi_review_mode'; // backward compatible alias
	const SHORTCODE_QUESTIONNAIRE = 'pcpi_questionnaire';

	public static function register_shortcodes(): void {
		add_shortcode( self::SHORTCODE_REVIEW, [ __CLASS__, 'shortcode_review' ] );
		add_shortcode( self::SHORTCODE_REVIEW_LEGACY, [ __CLASS__, 'shortcode_review' ] ); // keep old working
		add_shortcode( self::SHORTCODE_QUESTIONNAIRE, [ __CLASS__, 'shortcode_questionnaire' ] );
	}

	private static function yes( $v ): bool {
		return strtolower( (string) $v ) === 'yes';
	}

	private static function notice( string $msg ): string {
		return '<div class="pcpi-wf-notice" style="padding:12px;border:1px solid rgba(0,0,0,.12);border-radius:10px;background:#fff;">' . esc_html( $msg ) . '</div>';
	}

	/**
	 * [pcpi_questionnaire]
	 * URL:
	 *   /questionnaire/?workflow=<key>
	 * Optional applicant prefill:
	 *   /questionnaire/?workflow=<key>&parent_applicant_entry_id=<APPLICANT_ENTRY_ID>
	 */
	public static function shortcode_questionnaire( $atts ): string {

		$ctx = self::get_context();

		if ( empty( $ctx['is_questionnaire_page'] ) ) {
			return self::notice( 'Questionnaire mode is not active. Make sure you are on the questionnaire page.' );
		}

		if ( empty( $ctx['workflow'] ) ) {
			return self::notice( 'Unknown workflow. Check ?workflow=... in the URL.' );
		}

		if ( ! function_exists( 'gravity_form' ) ) {
			return self::notice( 'Gravity Forms is not available.' );
		}

		$atts = shortcode_atts(
			[
				'title'       => 'no',
				'description' => 'no',
			],
			(array) $atts,
			self::SHORTCODE_QUESTIONNAIRE
		);

		$source_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		if ( ! $source_id ) {
			return self::notice( 'This workflow does not define source_form_id.' );
		}

		ob_start();
		echo '<div class="pcpi-questionnaire-mode">';
			gravity_form(
				$source_id,
				self::yes( $atts['title'] ),
				self::yes( $atts['description'] ),
				false,
				null,
				true, // ✅ ajax ON (helps multipage + shortcode context)
				0,
				true
			);
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * [pcpi_review]  (preferred)
	 * [pcpi_review_mode] (legacy alias)
	 *
	 * URL:
	 *   /review/?workflow=<key>&entry_id=<SOURCE_ENTRY_ID>
	 */
	public static function shortcode_review( $atts ): string {

		$ctx = self::get_context();

		if ( empty( $ctx['is_review_page'] ) ) {
			return self::notice( 'Review mode is not active. Make sure you are on the review page.' );
		}

		if ( empty( $ctx['workflow'] ) ) {
			return self::notice( 'Unknown workflow. Check ?workflow=... in the URL.' );
		}

		if ( empty( $ctx['entry_id'] ) ) {
			return self::notice( 'Missing entry_id. Example: ?workflow=polygraph&entry_id=123' );
		}

		if ( ! function_exists( 'gravity_form' ) ) {
			return self::notice( 'Gravity Forms is not available.' );
		}

		$atts = shortcode_atts(
			[
				'title_source'       => 'no',
				'title_review'       => 'no',
				'description_source' => 'no',
				'description_review' => 'no',
			],
			(array) $atts,
			self::SHORTCODE_REVIEW
		);

		$source_id = (int) ( $ctx['workflow']['source_form_id'] ?? 0 );
		$review_id = (int) ( $ctx['workflow']['review_form_id'] ?? 0 );

		if ( ! $source_id || ! $review_id ) {
			return self::notice( 'This workflow is missing source_form_id or review_form_id.' );
		}

		ob_start();

		echo '<div class="pcpi-review-mode">';

			echo '<div class="pcpi-review-mode__source">';
			gravity_form(
				$source_id,
				self::yes( $atts['title_source'] ),
				self::yes( $atts['description_source'] ),
				false,
				null,
				true, // ✅ ajax ON
				0,
				true
			);
			echo '</div>';

			echo '<div class="pcpi-review-mode__review">';
			gravity_form(
				$review_id,
				self::yes( $atts['title_review'] ),
				self::yes( $atts['description_review'] ),
				false,
				null,
				true, // ✅ ajax ON
				0,
				true
			);
			echo '</div>';

		echo '</div>';

		return (string) ob_get_clean();
	}
}
