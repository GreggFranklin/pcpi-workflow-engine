/* global pcpiKioskLoader */
(function () {
  'use strict';

  // Bind once (even if GF re-renders)
  if (window.__pcpiKioskLoaderBound) return;
  window.__pcpiKioskLoaderBound = true;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  ready(function () {
    var cfg = window.pcpiKioskLoader || {};
    var workflowKey = String(cfg.workflowKey || '').trim();
    var onlyBodyClass = String(cfg.onlyBodyClass || '').trim();
    var timeoutMs = Number(cfg.timeoutMs || 4000);

    // Require workflow scoping if provided
    if (onlyBodyClass) {
      if (!document.body.classList.contains(onlyBodyClass)) return;
    } else if (workflowKey) {
      // Default expected class pattern:
      // pcpi-questionnaire-workflow-{workflowKey}
      var cls = 'pcpi-questionnaire-workflow-' + workflowKey;
      if (!document.body.classList.contains(cls)) return;
    }

    // Create overlay once
    var overlay = document.createElement('div');
    overlay.className = 'pcpi-gf-loading';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
      '<div class="pcpi-gf-loading__panel" role="status" aria-live="polite">' +
        '<span class="pcpi-gf-loading__spinner" aria-hidden="true"></span>' +
        '<span class="pcpi-gf-loading__text">Loading…</span>' +
      '</div>';

    document.body.appendChild(overlay);

    var hideTimer = null;

    function showLoader() {
      overlay.classList.add('is-active');

      // Failsafe: never allow "stuck" UI
      if (hideTimer) window.clearTimeout(hideTimer);
      hideTimer = window.setTimeout(hideLoader, timeoutMs);
    }

    function hideLoader() {
      overlay.classList.remove('is-active');
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
    }

    // Show on GF nav clicks (capture phase)
    document.addEventListener(
      'click',
      function (e) {
        var btn = e.target && e.target.closest
          ? e.target.closest('.gform_next_button, .gform_previous_button, .gform_button')
          : null;

        if (!btn) return;

        var form = btn.closest ? btn.closest('form[id^="gform_"]') : null;
        if (!form) return;

        showLoader();
      },
      true
    );

    function bindGFEvents() {
      // jQuery GF events (most reliable)
      if (window.jQuery) {
        window.jQuery(document)
          .on('gform_page_loaded', hideLoader)
          .on('gform_post_render', hideLoader)
          .on('gform_confirmation_loaded', hideLoader);
      }

      // Native events as backup
      document.addEventListener('gform_page_loaded', hideLoader);
      document.addEventListener('gform/post_render', hideLoader); // NEW
      //document.addEventListener('gform_post_render', hideLoader); // OLD
      document.addEventListener('gform_confirmation_loaded', hideLoader);

      // Extra: hide if hash changes
      window.addEventListener('hashchange', hideLoader);

      // Extra: hide on interaction (last resort)
      document.addEventListener('keydown', hideLoader);
      document.addEventListener('pointerdown', hideLoader);
    }

    bindGFEvents();
    
    // Hard failsafe — NEVER allow stuck loader
    setTimeout(hideLoader, 5000);

    // Ensure hidden on initial load
    window.addEventListener('load', hideLoader);
  });
})();