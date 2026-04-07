<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait PCPI_WFE_Trait_Assets {

	// CSS class prefixes used for mapping
	const SECTION_CLASS_PREFIX = 'pcpi-section-';
	const COMMENT_CLASS_PREFIX = 'pcpi-comment-';

	public static function maybe_enqueue_assets(): void {

		$ctx = self::get_context();
		if ( ( empty( $ctx['is_review_page'] ) && empty( $ctx['is_questionnaire_page'] ) ) || empty( $ctx['workflow'] ) ) {
			return;
		}

		$css_file       = PCPI_WF_ENGINE_DIR . 'assets/css/workflow-engine.css';
		$js_review_file = PCPI_WF_ENGINE_DIR . 'assets/js/review-mode.js';

		// Kiosk loader (questionnaire paging overlay)
		$js_kiosk_file  = PCPI_WF_ENGINE_DIR . 'assets/js/pcpi-kiosk-loader.js';

		wp_enqueue_style(
			'pcpi-workflow-engine',
			PCPI_WF_ENGINE_URL . 'assets/css/workflow-engine.css',
			[],
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : PCPI_WF_ENGINE_VERSION
		);

		// -------------------------------------------------
		// Kiosk Loader Overlay (Questionnaire pages)
		// -------------------------------------------------
		// We intentionally do NOT rely on a kiosk flag in context because
		// your current get_context() does not expose one consistently.
		// Safety/scoping is enforced by:
		// - only enqueueing on questionnaire pages
		// - requiring workflow_key
		// - JS additionally checking body class: pcpi-questionnaire-workflow-{key}
		//
		// If you later add a registry-driven kiosk flag, you can tighten this
		// condition (or filter it) without touching the JS.
		$enable_kiosk_loader = (
			! empty( $ctx['is_questionnaire_page'] )
			&& ! empty( $ctx['workflow_key'] )
			&& file_exists( $js_kiosk_file )
		);

		/**
		 * Allow enabling/disabling kiosk loader by filter.
		 *
		 * @param bool  $enable Whether to enable.
		 * @param array $ctx    Workflow Engine context.
		 */
		$enable_kiosk_loader = (bool) apply_filters( 'pcpi_workflow_engine_enable_kiosk_loader', $enable_kiosk_loader, $ctx );

		if ( $enable_kiosk_loader ) {

			wp_enqueue_script(
				'pcpi-workflow-engine-kiosk-loader',
				PCPI_WF_ENGINE_URL . 'assets/js/pcpi-kiosk-loader.js',
				[], // no hard dependency; will hook jQuery GF events if present
				(string) filemtime( $js_kiosk_file ),
				true
			);

			$workflow_key = sanitize_key( (string) $ctx['workflow_key'] );

			wp_add_inline_script(
				'pcpi-workflow-engine-kiosk-loader',
				'window.pcpiKioskLoader=' . wp_json_encode( [
					'workflowKey'   => $workflow_key,
					'onlyBodyClass' => 'pcpi-questionnaire-workflow-' . $workflow_key,
					'timeoutMs'     => (int) apply_filters( 'pcpi_kiosk_loader_timeout_ms', 4000, $ctx ),
				] ) . ';',
				'before'
			);
		}

		// -------------------------------------------------
		// Questionnaire UX: "Mark all as No" (Kiosk workflows only)
		// -------------------------------------------------
		// This must run inside class scope (trait) because get_context() is not public.
		// We scope it tightly:
		// - questionnaire pages only
		// - workflow entry_mode === 'kiosk'
		// - binds once in the browser
		if ( ! empty( $ctx['is_questionnaire_page'] ) ) {
			self::maybe_inline_toggle_all_no( $ctx );
		}

		// -------------------------------------------------
		// Questionnaire UX: Auto-scroll to next radio question (feature flagged)
		// -------------------------------------------------
		// Scope:
		// - questionnaire pages only
		// - workflow must enable: workflow['features']['auto_scroll_radios'] === true
		// - only runs inside .pcpi-questionnaire-workflow-{key} wrapper
		if ( ! empty( $ctx['is_questionnaire_page'] ) ) {
			self::maybe_inline_auto_scroll_radios( $ctx );
		}

		// -------------------------------------------------
		// Review Mode assets (injection JS)
		// -------------------------------------------------
		// Only need the injection JS on review pages.
		if ( ! empty( $ctx['is_review_page'] ) ) {

			wp_enqueue_script(
				'pcpi-workflow-engine-review',
				PCPI_WF_ENGINE_URL . 'assets/js/review-mode.js',
				[ 'jquery' ],
				file_exists( $js_review_file ) ? (string) filemtime( $js_review_file ) : PCPI_WF_ENGINE_VERSION,
				true
			);

			$data = [
				'sourceFormId'       => (int) ( $ctx['workflow']['source_form_id'] ?? 0 ),
				'reviewFormId'       => (int) ( $ctx['workflow']['review_form_id'] ?? 0 ),
				'sectionClassPrefix' => (string) apply_filters( 'pcpi_workflow_engine_section_prefix', self::SECTION_CLASS_PREFIX ),
				'commentClassPrefix' => (string) apply_filters( 'pcpi_workflow_engine_comment_prefix', self::COMMENT_CLASS_PREFIX ),
				'debug'              => self::debug_enabled(),
			];

			/**
			 * Review mode paging fix:
			 * - Hide "Submit" on ALL pages except the last page for BOTH:
			 *   - Source (questionnaire) form (read-only + injected staff comments)
			 *   - Review form (staff notes)
			 * - Hide Save & Continue link if present
			 *
			 * Why JS?
			 * In shortcode + AJAX paging contexts, GF PHP "current page" signals can be unreliable
			 * during initial render. GF reliably fires gform_page_loaded with the correct page.
			 */
			$ids = array_values(
				array_filter(
					array_unique(
						[
							(int) ( $ctx['workflow']['source_form_id'] ?? 0 ),
							(int) ( $ctx['workflow']['review_form_id'] ?? 0 ),
						]
					)
				)
			);

			// Determine max page count for each GF form (AJAX multipage responses may only include the current page markup).
			$max_pages = [];
			if ( class_exists( 'GFAPI' ) && class_exists( 'GFFormDisplay' ) ) {
				foreach ( $ids as $fid ) {
					$form = GFAPI::get_form( (int) $fid );
					if ( is_array( $form ) ) {
						$max_pages[ (int) $fid ] = (int) GFFormDisplay::get_max_page_number( $form );
					}
				}
			}

			// Expose to review-mode.js (and our inline submit hider).
			$data['maxPages'] = $max_pages;

			wp_add_inline_script(
				'pcpi-workflow-engine-review',
				'window.PCPI_REVIEW_MODE=' . wp_json_encode( $data ) . ';',
				'before'
			);

			$inline = '
(function($){
  var formIds = ' . wp_json_encode( $ids ) . ';
  var maxPages = ' . wp_json_encode( $max_pages ) . ';

  function updateSubmitVisibility(formId){
    var $form = $("#gform_" + formId);
    if(!$form.length) return;

    // Only for multipage forms
    var $pages = $form.find(".gform_page");
    if(!$pages.length) return;

    var lastPage = parseInt(maxPages[formId], 10) || $pages.length || 1;

    // GF stores current page in hidden input
    var currentPage = parseInt($form.find("input[name=\'gform_source_page_number_" + formId + "\'], input[name=\'gform_page_number_" + formId + "\']").val(), 10);
    if(!currentPage || currentPage < 1) currentPage = 1;

    var $submit = $form.find("#gform_submit_button_" + formId);
    if(!$submit.length){
      $submit = $form.find("input.gform_button[type=submit], button.gform_button[type=submit]");
    }

    if(currentPage < lastPage){
      $submit.hide();
    } else {
      $submit.show();
    }

    // Hide Save & Continue if present
    $form.find(".gform_save_link, a.gform_save_link").hide();
  }

  // Initial load
  $(function(){
    for(var i=0;i<formIds.length;i++){
      updateSubmitVisibility(formIds[i]);
    }
  });

  // On GF page change (AJAX paging too)
  $(document).on("gform_page_loaded", function(e, formId, currentPage){
    formId = parseInt(formId, 10);
    if(formIds.indexOf(formId) !== -1){
      updateSubmitVisibility(formId);
    }
  });

})(jQuery);
';

			wp_add_inline_script( 'pcpi-workflow-engine-review', $inline, 'after' );
		}
	}

	/**
	 * Kiosk UX: "Mark all as No" toggle (sets all to No, second click clears)
	 *
	 * Button markup:
	 * <button type="button" class="gf-toggle-all-no" data-target=".pcpi-yn-section-1">
	 *   Mark all as No
	 * </button>
	 *
	 * Filters:
	 * - pcpi_wfe_toggle_all_no_enabled (bool, default true)
	 * - pcpi_wfe_toggle_all_no_targets (array of selectors, default ['.pcpi-yn-section-1'])
	 *
	 * @param array $ctx Workflow Engine context.
	 */
	private static function maybe_inline_toggle_all_no( array $ctx ): void {

		if ( ! apply_filters( 'pcpi_wfe_toggle_all_no_enabled', true, $ctx ) ) {
			return;
		}

		if ( empty( $ctx['workflow'] ) || ! is_array( $ctx['workflow'] ) ) {
			return;
		}

		$wf = $ctx['workflow'];

		// Only for kiosk workflows.
		if ( empty( $wf['entry_mode'] ) || $wf['entry_mode'] !== 'kiosk' ) {
			return;
		}

		$form_id = ! empty( $wf['source_form_id'] ) ? absint( $wf['source_form_id'] ) : 0;
		if ( $form_id <= 0 ) {
			return;
		}

		// Defaults used only if button has no data-target.
		$default_targets = [ '.pcpi-yn-section-1' ];
		$targets = apply_filters( 'pcpi_wfe_toggle_all_no_targets', $default_targets, $ctx );

		if ( empty( $targets ) || ! is_array( $targets ) ) {
			$targets = $default_targets;
		}

		$payload = [
			'expectedFormId' => $form_id,
			'targets'        => array_values( array_filter( array_map( 'strval', $targets ) ) ),
		];

		// Stable host handle for inline UX scripts.
		$handle = 'pcpi-workflow-engine-ux';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				'',
				[],
				defined( 'PCPI_WF_ENGINE_VERSION' ) ? PCPI_WF_ENGINE_VERSION : null,
				true
			);
		}

		wp_enqueue_script( $handle );

		wp_add_inline_script( $handle, self::build_toggle_all_no_js( $payload ), 'after' );
	}

	/**
	 * Questionnaire UX: auto-scroll to next visible radio question.
	 *
	 * Enabled per-workflow:
	 *   $workflow['features']['auto_scroll_radios'] === true
	 *
	 * Filters:
	 * - pcpi_wfe_auto_scroll_radios_enabled (bool, default true)
	 * - pcpi_wfe_auto_scroll_radios_offset_px (int, default 100)
	 * - pcpi_wfe_auto_scroll_radios_duration_ms (int, default 550)
	 *
	 * @param array $ctx Workflow Engine context.
	 */
	private static function maybe_inline_auto_scroll_radios( array $ctx ): void {

		if ( ! apply_filters( 'pcpi_wfe_auto_scroll_radios_enabled', true, $ctx ) ) {
			return;
		}

		if ( empty( $ctx['workflow'] ) || ! is_array( $ctx['workflow'] ) ) {
			return;
		}

		if ( empty( $ctx['workflow_key'] ) ) {
			return;
		}

		$wf = $ctx['workflow'];

		$features = [];
		if ( isset( $wf['features'] ) && is_array( $wf['features'] ) ) {
			$features = $wf['features'];
		}

		// Feature flag required.
		if ( empty( $features['auto_scroll_radios'] ) ) {
			return;
		}

		$workflow_key = sanitize_key( (string) $ctx['workflow_key'] );
		if ( $workflow_key === '' ) {
			return;
		}

		$offset_px   = (int) apply_filters( 'pcpi_wfe_auto_scroll_radios_offset_px', 100, $ctx );
		$duration_ms = (int) apply_filters( 'pcpi_wfe_auto_scroll_radios_duration_ms', 550, $ctx );

		$payload = [
			'onlyClass'   => 'pcpi-questionnaire-workflow-' . $workflow_key,
			'offsetPx'    => max( 0, $offset_px ),
			'durationMs'  => max( 200, $duration_ms ),
		];

		// Stable host handle for inline UX scripts.
		$handle = 'pcpi-workflow-engine-ux';

		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				'',
				[],
				defined( 'PCPI_WF_ENGINE_VERSION' ) ? PCPI_WF_ENGINE_VERSION : null,
				true
			);
		}

		wp_enqueue_script( $handle );

		wp_add_inline_script( $handle, self::build_auto_scroll_radios_js( $payload ), 'after' );
	}

	/**
	 * Build the inline JS for auto-scroll-radios.
	 *
	 * @param array $payload onlyClass + offsetPx + durationMs.
	 */
	private static function build_auto_scroll_radios_js( array $payload ): string {

		$json = wp_json_encode( $payload );

		return <<<JS
(function () {
	"use strict";

	// Bind-once guard (prevents duplicate event listeners, AJAX re-renders, etc.)
	if (window.__pcpiWfeAutoScrollRadiosBound) return;
	window.__pcpiWfeAutoScrollRadiosBound = true;

	var cfg = $json;
	if (!cfg || !cfg.onlyClass) return;

	function isVisible(el) {
		if (!el) return false;
		var style = window.getComputedStyle(el);
		return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
	}

	function getNextQuestionField(currentField) {
		var form = currentField.closest('form');
		if (!form) return null;

		var fields = Array.prototype.slice.call(form.querySelectorAll('.gfield')).filter(isVisible);
		var idx = fields.indexOf(currentField);
		if (idx === -1) return null;

		for (var i = idx + 1; i < fields.length; i++) {
			var f = fields[i];
			if (!isVisible(f)) continue;

			var hasRadio = !!f.querySelector('.gfield_radio input[type="radio"]');
			if (hasRadio) return f;
		}

		return null;
	}

	function easeInOutCubic(t) {
		return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
	}

	function scrollToField(field) {
		if (!field) return;

		var SCROLL_OFFSET_PX = parseInt(cfg.offsetPx, 10) || 0;
		var duration = parseInt(cfg.durationMs, 10) || 550;

		var rect = field.getBoundingClientRect();
		var targetY = window.scrollY + rect.top - SCROLL_OFFSET_PX;
		var startY = window.scrollY;
		var distance = targetY - startY;

		var startTime = null;

		function animateScroll(currentTime) {
			if (!startTime) startTime = currentTime;
			var elapsed = currentTime - startTime;
			var progress = Math.min(elapsed / duration, 1);
			var eased = easeInOutCubic(progress);

			window.scrollTo(0, startY + distance * eased);

			if (progress < 1) {
				requestAnimationFrame(animateScroll);
			}
		}

		requestAnimationFrame(animateScroll);
	}

	document.addEventListener('change', function (e) {
		var input = e.target;
		if (!input || input.type !== 'radio') return;

		// Scope to workflow wrapper only (pcpi-questionnaire-workflow-{key})
		var scopedRoot = input.closest('.' + cfg.onlyClass);
		if (!scopedRoot) return;

		var currentField = input.closest('.gfield');
		if (!currentField) return;

		var nextField = getNextQuestionField(currentField);

		// Tiny delay lets the selected UI state render before scroll (feels nicer)
		window.setTimeout(function(){ scrollToField(nextField); }, 120);

	}, true);

})();
JS;
	}

	/**
	 * Build the inline JS for toggle-all-no.
	 *
	 * @param array $payload expectedFormId + targets.
	 */
	private static function build_toggle_all_no_js( array $payload ): string {

		$json = wp_json_encode( $payload );

		return <<<JS
(function () {
	"use strict";

	// Bind-once guard (prevents duplicate event listeners, AJAX re-renders, etc.)
	if (window.__pcpiWfeToggleAllNoBound) return;
	window.__pcpiWfeToggleAllNoBound = true;

	var cfg = $json;
	if (!cfg || !cfg.expectedFormId) return;

	function normalize(s){ return String(s || "").trim().toLowerCase(); }

	document.addEventListener('click', function (e) {

		var btn = e.target && e.target.closest ? e.target.closest('.gf-toggle-all-no') : null;
		if (!btn) return;

		var form = btn.closest('form[id^="gform_"]');
		if (!form) return;

		var formId = parseInt(String(form.id).replace('gform_', ''), 10);
		if (!formId || formId !== parseInt(cfg.expectedFormId, 10)) return;

		var selector = btn.getAttribute('data-target');

		// Prefer per-button selector; otherwise fall back to configured targets
		var selectors = [];
		if (selector && selector.trim()) {
			selectors = [ selector.trim() ];
		} else if (Array.isArray(cfg.targets) && cfg.targets.length) {
			selectors = cfg.targets.slice();
		} else {
			return;
		}

		// Collect all matching field wrappers
		var fields = [];
		selectors.forEach(function(sel){
			try {
				form.querySelectorAll(sel).forEach(function(node){ fields.push(node); });
			} catch (err) {}
		});
		if (!fields.length) return;

		// Determine if *every* field has "No" selected
		var allNo = true;

		fields.forEach(function (field) {
			var checked = field.querySelector('input[type="radio"]:checked');
			if (!checked) {
				allNo = false;
				return;
			}

			var label = field.querySelector('label[for="' + checked.id + '"]');
			if (!label || normalize(label.textContent) !== 'no') {
				allNo = false;
			}
		});

		fields.forEach(function (field) {

			var radios = field.querySelectorAll('input[type="radio"]');
			if (!radios || !radios.length) return;

			if (allNo) {
				// CLEAR
				var checked = field.querySelector('input[type="radio"]:checked');
				if (checked) {
					checked.checked = false;
					checked.dispatchEvent(new Event('change', { bubbles: true }));
					checked.dispatchEvent(new Event('input',  { bubbles: true }));
				}
				return;
			}

			// SET TO NO
			radios.forEach(function (radio) {
				var label = field.querySelector('label[for="' + radio.id + '"]');
				if (!label) return;

				if (normalize(label.textContent) === 'no') {
					if (!radio.checked) {
						radio.checked = true;
						radio.dispatchEvent(new Event('change', { bubbles: true }));
						radio.dispatchEvent(new Event('input',  { bubbles: true }));
					}
				}
			});
		});

	}, true);

})();
JS;
	}

	public static function maybe_add_body_classes( array $classes ): array {

		$ctx = self::get_context();

		if ( ! empty( $ctx['is_review_page'] ) ) {
			$classes[] = 'pcpi-review-page';
			if ( ! empty( $ctx['workflow_key'] ) ) {
				$classes[] = 'pcpi-review-workflow-' . sanitize_html_class( $ctx['workflow_key'] );
			}
		}
		
		if ( ! empty( $ctx['workflow']['entry_mode'] ) ) {
    			$classes[] = 'pcpi-entry-mode-' . sanitize_html_class( $ctx['workflow']['entry_mode'] );
		}

		if ( ! empty( $ctx['is_questionnaire_page'] ) ) {
			$classes[] = 'pcpi-questionnaire-page';
			if ( ! empty( $ctx['workflow_key'] ) ) {
				$classes[] = 'pcpi-questionnaire-workflow-' . sanitize_html_class( $ctx['workflow_key'] );
			}
		}

		return $classes;
	}
}