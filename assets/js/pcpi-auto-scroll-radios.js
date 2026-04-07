(function () {
  // Prevent double-binding if assets load more than once.
  if (window.__pcpiAutoScrollRadiosBound) return;
  window.__pcpiAutoScrollRadiosBound = true;

  // Default scroll offset. Can be overridden via localized data.
  function getScrollOffsetPx() {
    if (window.PCPI_AUTO_SCROLL && typeof window.PCPI_AUTO_SCROLL.scrollOffsetPx === 'number') {
      return window.PCPI_AUTO_SCROLL.scrollOffsetPx;
    }
    return 100;
  }

  function isVisible(el) {
    if (!el) return false;
    const style = window.getComputedStyle(el);
    return style.display !== 'none' && style.visibility !== 'hidden' && el.offsetParent !== null;
  }

  function getNextQuestionField(currentField) {
    const form = currentField.closest('form');
    if (!form) return null;

    // Visible GF fields only (GF uses hidden fields + conditional logic)
    const fields = Array.from(form.querySelectorAll('.gfield')).filter(isVisible);
    const idx = fields.indexOf(currentField);
    if (idx === -1) return null;

    for (let i = idx + 1; i < fields.length; i++) {
      const f = fields[i];
      if (!isVisible(f)) continue;

      // A "question" = a field with radios
      const hasRadio = !!f.querySelector('.gfield_radio input[type="radio"]');
      if (hasRadio) return f;
    }
    return null;
  }

  function scrollToField(field) {
    if (!field) return;

    const SCROLL_OFFSET_PX = getScrollOffsetPx();

    const rect = field.getBoundingClientRect();
    const targetY = window.scrollY + rect.top - SCROLL_OFFSET_PX;
    const startY = window.scrollY;
    const distance = targetY - startY;

    const duration = 550;
    let startTime = null;

    function easeInOutCubic(t) {
      return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function animateScroll(currentTime) {
      if (!startTime) startTime = currentTime;
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const eased = easeInOutCubic(progress);

      window.scrollTo(0, startY + distance * eased);

      if (progress < 1) {
        requestAnimationFrame(animateScroll);
      }
    }

    requestAnimationFrame(animateScroll);
  }

  document.addEventListener('change', function (e) {
    const input = e.target;
    if (!input || input.type !== 'radio') return;

    // Scope: workflow wrapper class: .pcpi-questionnaire-workflow-{key}
    // We match any wrapper that starts with pcpi-questionnaire-workflow-
    const scopedRoot = input.closest('[class*="pcpi-questionnaire-workflow-"]');
    if (!scopedRoot) return;

    // Extra safety: only run if this workflow was enabled server-side
    // by adding the allowed workflow classes to PCPI_AUTO_SCROLL.allowedWorkflowClasses
    if (window.PCPI_AUTO_SCROLL && Array.isArray(window.PCPI_AUTO_SCROLL.allowedWorkflowClasses)) {
      const allowed = window.PCPI_AUTO_SCROLL.allowedWorkflowClasses;
      const matches = allowed.some((cls) => scopedRoot.classList.contains(cls));
      if (!matches) return;
    }

    const currentField = input.closest('.gfield');
    if (!currentField) return;

    const nextField = getNextQuestionField(currentField);

    window.setTimeout(() => scrollToField(nextField), 120);
  });
})();