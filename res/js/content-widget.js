/**
 * content-widget.js — Behavior controller for ContentWidget.ss
 *
 * All HTML lives in the template. JS only toggles visibility,
 * fetches endpoints, and fills text containers with responses.
 *
 * Requires: ai-utils.js
 */
(function () {
  'use strict';

  function initWidget(root) {
    console.log('[ContentWidget] Initializing', root);
    var cfg = root.dataset;
    var display = root.querySelector('[data-role="content-widget-display"]');
    var textEl = root.querySelector('[data-role="content-widget-text"]');
    var editForm = root.querySelector('[data-role="content-widget-edit-form"]');
    var textarea = root.querySelector('[data-role="content-widget-textarea"]');
    var loadingEl = root.querySelector('[data-role="ai-loading"]');
    var hintEl = root.querySelector('[data-role="ai-hint"]');
    var generateBtn = root.querySelector('[data-action="ai-generate"]');
    var saveBtns = root.querySelectorAll('[data-action="manual-save"]');
    var showEditBtn = root.querySelector('[data-action="show-edit"]');
    var hideEditBtns = root.querySelectorAll('[data-action="hide-edit"]');
    var disclaimerEl = root.querySelector('[data-role="ai-disclaimer"]');
    var resumeToken = null;

    function loading(on) {
      if (loadingEl) loadingEl.style.display = on ? '' : 'none';
      if (generateBtn) generateBtn.disabled = on;
      root.classList.toggle('ai-loading', on);
    }

    function showView() {
      if (display) display.style.display = '';
      if (editForm) editForm.style.display = 'none';
      resumeToken = null;
      if (hintEl) hintEl.style.display = 'none';
    }

    function showEdit() {
      if (display) display.style.display = 'none';
      if (editForm) editForm.style.display = '';
    }

    function onGenerated(data) {
      loading(false);
      if (data.error) return;
      if (data.resumeToken) {
        resumeToken = data.resumeToken;
        if (textarea) textarea.value = AIUtils.stripTags(data.content || '');
        if (hintEl) hintEl.style.display = '';
        return;
      }
      if (data.done) window.location.reload();
    }

    function onSaved(content, fromAI) {
      if (textEl) textEl.innerHTML = AIUtils.textToHtml(content);
      if (fromAI && disclaimerEl) disclaimerEl.style.display = '';
      showView();
    }

    if (textarea && textarea.value) textarea.value = AIUtils.stripTags(textarea.value);

    if (showEditBtn) showEditBtn.addEventListener('click', showEdit);
    hideEditBtns.forEach(function (b) {
      b.addEventListener('click', showView);
    });

    if (generateBtn) {
      generateBtn.addEventListener('click', function () {
        resumeToken = null;
        loading(true);
        AIUtils.postForm('content/generate', {
          entity_id: cfg.entityId, entity_class: cfg.entityClass, modelId: cfg.modelId || ''
        }).then(onGenerated).catch(function () {
          loading(false);
        });
      });
    }

    saveBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var content = textarea ? textarea.value : '';
        loading(true);
        if (resumeToken) {
          AIUtils.postForm('content/resume', {
            resumeToken: resumeToken, decision: 'edit', content: content,
            entity_id: cfg.entityId, entity_class: cfg.entityClass, modelId: cfg.modelId || ''
          }).then(function (d) {
            loading(false);
            if (d.error) return;
            if (d.resumeToken) return onGenerated(d);
            if (d.done) onSaved(content, !!resumeToken);
          }).catch(function () {
            loading(false);
          });
        } else {
          AIUtils.postForm('content/save', {
            entity_id: cfg.entityId, entity_class: cfg.entityClass, content: content
          }).then(function (d) {
            loading(false);
            if (d.error) return;
            if (d.done) onSaved(content, false);
          }).catch(function () {
            loading(false);
          });
        }
      });
    });
  }

  AIUtils.createWidgetLoader({
    selector: '[data-role="content-widget-root"]',
    initAttr: 'data-ai-init',
    init: initWidget
  });
})();
