(function () {
  "use strict";

  function dbg() {
    try {
      if (window.PCPI_REVIEW_MODE && window.PCPI_REVIEW_MODE.debug) {
        console.log.apply(console, arguments);
      }
    } catch (e) {}
  }

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function getCfg() {
    return window.PCPI_REVIEW_MODE || {};
  }

  function getWrapper(formId) {
    return document.getElementById("gform_wrapper_" + formId);
  }

  function getForm(formId) {
    return document.getElementById("gform_" + formId);
  }

  function getReviewSubmitEl(reviewFormId) {
    // Preferred: GF's button id.
    var btn = document.getElementById("gform_submit_button_" + reviewFormId);
    if (btn) return btn;

    // Fallback: any submit in the review form.
    var formEl = getForm(reviewFormId);
    if (!formEl) return null;
    return $("button[type='submit'], input[type='submit']", formEl);
  }

  function getSourceCurrentPage(sourceFormId) {
    // Most reliable: hidden input name gform_source_page_number_{ID}
    var key = "gform_source_page_number_" + sourceFormId;
    var input = document.querySelector("input[name='" + key + "']");
    if (input && input.value) return parseInt(input.value, 10) || 1;

    // Fallback: gform_current_page
    var cur = document.querySelector("input[name='gform_current_page']");
    if (cur && cur.value) return parseInt(cur.value, 10) || 1;

    // Final fallback
    return 1;
  }

  function getSourceLastPage(sourceFormId, wrapperSource) {
    // Count page containers. Typical IDs: gform_page_{formId}_{pageNumber}
    var pages = $all("[id^='gform_page_" + sourceFormId + "_']", wrapperSource || document);
    if (pages.length) return pages.length;
    return 1;
  }

  function setReviewSubmitVisible(isVisible, reviewFormId) {
    var btn = getReviewSubmitEl(reviewFormId);
    if (!btn) return;

    // Hide/show footer if possible for cleaner layout.
    var footer = btn.closest ? btn.closest(".gform_footer") : null;
    if (footer) {
      footer.style.display = isVisible ? "" : "none";
    } else {
      btn.style.display = isVisible ? "" : "none";
    }
  }

  function syncReviewSubmit() {
    var cfg = getCfg();
    var sourceFormId = parseInt(cfg.sourceFormId || 0, 10);
    var reviewFormId = parseInt(cfg.reviewFormId || 0, 10);
    if (!sourceFormId || !reviewFormId) return;

    var wrapperSource = getWrapper(sourceFormId);
    if (!wrapperSource) return;

    var current = getSourceCurrentPage(sourceFormId);
    var last = getSourceLastPage(sourceFormId, wrapperSource);
    var show = current >= last;

    setReviewSubmitVisible(show, reviewFormId);

    dbg("[PCPI] submit sync", { current: current, last: last, show: show });
  }

  function doCommentInjection() {
    var cfg = getCfg();
    var sourceFormId = parseInt(cfg.sourceFormId || 0, 10);
    var reviewFormId = parseInt(cfg.reviewFormId || 0, 10);
    if (!sourceFormId || !reviewFormId) return;

    var wrapperSource = getWrapper(sourceFormId);
    var wrapperReview = getWrapper(reviewFormId);
    var reviewForm = getForm(reviewFormId);
    if (!wrapperSource || !wrapperReview || !reviewForm) return;

    var sectionPrefix = (cfg.sectionClassPrefix || "pcpi-section-").toString();
    var commentPrefix = (cfg.commentClassPrefix || "pcpi-comment-").toString();

    function placeAfterSection(key, gfield) {
      var sel = ".gfield.gsection." + sectionPrefix + key;
      var sectionEl = wrapperSource.querySelector(sel);
      if (!sectionEl) {
        dbg("[PCPI] section not found", key);
        return false;
      }

      var node = sectionEl.nextElementSibling;
      var last = sectionEl;
      while (node) {
        if (node.classList && node.classList.contains("gsection")) break;
        if (node.classList && node.classList.contains("gfield")) last = node;
        node = node.nextElementSibling;
      }
      last.insertAdjacentElement("afterend", gfield);
      return true;
    }

    
    function getReviewFieldsContainer(){
      // Prefer the UL.gform_fields container inside the review form.
      return wrapperReview.querySelector(".gform_body .gform_fields") || wrapperReview.querySelector(".gform_body") || wrapperReview;
    }

    function returnInjectedFieldsToReview(){
      var container = getReviewFieldsContainer();
      if(!container) return;
      // Any comment fields currently living in the SOURCE wrapper should be returned
      // before GF swaps the page markup (AJAX multipage).
      var inSource = wrapperSource.querySelectorAll(".gfield[class*='" + commentPrefix + "']");
      inSource.forEach(function(el){
        container.appendChild(el);
      });
    }
var commentFields = wrapperReview.querySelectorAll(".gfield[class*='" + commentPrefix + "']");
    commentFields.forEach(function (gfield) {
      var m = gfield.className.match(new RegExp(commentPrefix + "([a-z0-9_-]+)", "i"));
      if (!m) return;

      var key = (m[1] || "").toLowerCase();
      if (!key) return;

      if (!placeAfterSection(key, gfield)) return;

      // Ensure inputs submit with the REVIEW form.
      gfield.querySelectorAll("input,textarea,select,button").forEach(function (el) {
        el.setAttribute("form", "gform_" + reviewFormId);
      });
    });

    // Hide the review form body (we only want its footer/button + hidden fields).
    var body = wrapperReview.querySelector(".gform_body");
    if (body) body.style.display = "none";

    dbg("[PCPI] injection complete");

    // Expose helper for paging events.
    doCommentInjection.returnToReview = returnInjectedFieldsToReview;

  }

  function boot() {
    doCommentInjection();
    syncReviewSubmit();

    // Bind once: before GF swaps multipage DOM, return injected comment fields
    // to the review form so they aren't destroyed when the source page markup is replaced.
    if (!boot._bound) {
      boot._bound = true;

      var cfg = getCfg();
      var sourceFormId = parseInt(cfg.sourceFormId || 0, 10);

      var wrapperSource = getWrapper(sourceFormId);
      var sourceForm = getForm(sourceFormId);

      var returnNow = function () {
        try {
          if (typeof doCommentInjection.returnToReview === "function") {
            doCommentInjection.returnToReview();
          }
        } catch (e) {}
      };

      // Capture clicks on Next/Previous
      if (wrapperSource && wrapperSource.addEventListener) {
        wrapperSource.addEventListener(
          "click",
          function (e) {
            var t = e && e.target;
            if (!t) return;

            // Next/Previous buttons can be <input> or <button>
            var btn =
              (t.closest && t.closest(".gform_next_button, .gform_previous_button")) ||
              (t.classList && (t.classList.contains("gform_next_button") || t.classList.contains("gform_previous_button")) ? t : null);

            if (btn) returnNow();
          },
          true
        );
      }

      // Also capture submits (edge cases)
      if (sourceForm && sourceForm.addEventListener) {
        sourceForm.addEventListener("submit", returnNow, true);
      }
    }

    // Run again shortly after load (GF can finish rendering late).
    setTimeout(doCommentInjection, 0);
    setTimeout(doCommentInjection, 50);
    setTimeout(doCommentInjection, 150);

    setTimeout(syncReviewSubmit, 250);
    setTimeout(syncReviewSubmit, 750);
  }

// Initial boot.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // GF multipage AJAX event: re-sync when a new page loads.
  if (window.jQuery) {
    window.jQuery(document).on("gform_page_loaded", function (event, formId, currentPage) {
      var cfg = getCfg();
      var sourceFormId = parseInt(cfg.sourceFormId || 0, 10);
      if (parseInt(formId, 10) !== sourceFormId) return;

      dbg("[PCPI] gform_page_loaded", { formId: formId, currentPage: currentPage });

      // After AJAX paging, the DOM updates—re-inject comment fields and re-sync submit visibility.
      setTimeout(doCommentInjection, 0);
      setTimeout(doCommentInjection, 50);
      setTimeout(doCommentInjection, 150);

      setTimeout(syncReviewSubmit, 0);
      setTimeout(syncReviewSubmit, 50);
      setTimeout(syncReviewSubmit, 150);
    });
  }
})();
