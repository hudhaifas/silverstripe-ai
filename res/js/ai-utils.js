/**
 * ai-utils.js — Shared utilities for AI widgets
 *
 * Common functions used by agent-widget.js and content-widget.js
 */
(function (global) {
  'use strict';

  var AIUtils = {};

  /**
   * Escape HTML to prevent XSS
   * @param {string} str
   * @returns {string}
   */
  AIUtils.escapeHtml = function (str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  };

  /**
   * Strip HTML tags from string
   * @param {string} html
   * @returns {string}
   */
  AIUtils.stripTags = function (html) {
    var div = document.createElement('div');
    div.innerHTML = html;
    return (div.textContent || '').trim();
  };

  var API_PREFIX = 'api/ai/';

  /**
   * POST request with FormData
   * @param {string} endpoint - e.g., 'content/generate', 'agent/chat'
   * @param {Object} body - key/value pairs
   * @returns {Promise<Object>}
   */
  AIUtils.postForm = function (endpoint, body) {
    var fd = new FormData();
    Object.keys(body).forEach(function (k) {
      if (body[k] != null) fd.append(k, body[k]);
    });
    return fetch(API_PREFIX + endpoint, {method: 'POST', body: fd}).then(function (r) {
      return r.json();
    });
  };

  /**
   * POST request with JSON body
   * @param {string} endpoint - e.g., 'agent/chat', 'agent/resume'
   * @param {Object} body
   * @returns {Promise<{response: Response, data: Object}>}
   */
  AIUtils.postJson = function (endpoint, body) {
    return fetch(API_PREFIX + endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        return {response: response, data: data};
      });
    });
  };

  /**
   * Convert plain text to HTML paragraphs
   * @param {string} text
   * @returns {string}
   */
  AIUtils.textToHtml = function (text) {
    return text.split(/\n\n+/).reduce(function (html, para) {
      para = para.trim();
      if (!para) return html;
      para = AIUtils.escapeHtml(para);
      return html + '<p>' + para.replace(/\n/g, '<br>') + '</p>';
    }, '');
  };

  /**
   * Parse JSON from a script element
   * @param {string} elementId
   * @param {Object} fallback
   * @returns {Object}
   */
  AIUtils.parseJsonScript = function (elementId, fallback) {
    try {
      var el = document.getElementById(elementId);
      if (el) return JSON.parse(el.textContent);
    } catch (e) {
      console.warn('[AIUtils] Failed to parse JSON from #' + elementId, e);
    }
    return fallback || {};
  };

  /**
   * Clone a <template> element by ID
   * @param {string} templateId - ID of the template element
   * @returns {DocumentFragment|null}
   */
  AIUtils.cloneTemplate = function (templateId) {
    var tpl = document.getElementById(templateId);
    return tpl ? tpl.content.cloneNode(true) : null;
  };

  /**
   * Get a template element by ID (for repeated cloning)
   * @param {string} templateId - ID of the template element
   * @returns {HTMLTemplateElement|null}
   */
  AIUtils.getTemplate = function (templateId) {
    return document.getElementById(templateId);
  };

  /**
   * Create a widget initializer with MutationObserver support
   * @param {Object} options
   * @param {string} options.selector - CSS selector for widget root
   * @param {string} options.initAttr - data attribute to mark initialized widgets
   * @param {Function} options.init - initialization function(root)
   */
  AIUtils.createWidgetLoader = function (options) {
    var selector = options.selector;
    var initAttr = options.initAttr || 'data-ai-init';
    var initFn = options.init;

    function maybeInit(el) {
      if (el.getAttribute(initAttr)) return;
      el.setAttribute(initAttr, '1');
      try {
        initFn(el);
      } catch (e) {
        console.error('[AIUtils] Widget init error:', e);
      }
    }

    function initAll() {
      document.querySelectorAll(selector).forEach(maybeInit);
    }

    function setupObserver() {
      new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          mutation.addedNodes.forEach(function (node) {
            if (node.nodeType !== 1) return;
            if (node.matches && node.matches(selector)) maybeInit(node);
            if (node.querySelectorAll) {
              node.querySelectorAll(selector).forEach(maybeInit);
            }
          });
        });
      }).observe(document.body, {childList: true, subtree: true});
    }

    // Initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () {
        initAll();
        setupObserver();
      });
    } else {
      initAll();
      setupObserver();
    }
  };

  // Export to global scope
  global.AIUtils = AIUtils;

})(window);
