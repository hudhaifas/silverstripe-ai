/**
 * Agent Chat Widget
 *
 * Vanilla JavaScript implementation for the agent chat sidebar.
 * All HTML lives in the template (AgentWidget.ss). JS only handles
 * state, fetches endpoints, and fills containers with responses.
 *
 * Requires: ai-utils.js
 */
(function () {
  'use strict';

  function initWidget(root) {
    var cfg = root.dataset;
    var messagesContainer = document.getElementById('agent-messages');
    var input = document.getElementById('agent-input');
    var sendBtn = document.getElementById('agent-send');
    var expandBtn = document.getElementById('agent-expand');
    var emptyState = document.getElementById('agent-empty-state');
    var headerIcon = root.querySelector('.genai-icon');

    // Templates
    var tplMessage = AIUtils.getTemplate('agent-tpl-message');
    var tplLoading = AIUtils.getTemplate('agent-tpl-loading');
    var tplInterrupt = AIUtils.getTemplate('agent-tpl-interrupt');
    var tplInterruptAction = AIUtils.getTemplate('agent-tpl-interrupt-action');

    // Localized strings
    var strings = AIUtils.parseJsonScript('agent-strings', {});

    if (!messagesContainer || !input || !sendBtn) {
      console.error('[Agent] Missing DOM elements');
      return;
    }

    // Configuration
    var entityClass = cfg.entityClass;
    var entityId = cfg.entityId;
    var entityName = cfg.entityName;

    // State
    var isLoading = false;
    var isExpanded = false;
    var messages = [];
    var threadId = crypto.randomUUID();
    var backdrop = null;
    var originalParent = null;
    var originalNextSibling = null;

    console.log('[Agent] Initialized:', {entityClass: entityClass, entityId: entityId});

    function renderMessageContent(msg) {
      if (msg.isHtml) return msg.content;
      if (msg.blocks && msg.blocks.length > 0) {
        return msg.blocks.map(function (block) {
          if (block.type === 'entity_reference' && block.link) {
            return '<a href="' + AIUtils.escapeHtml(block.link) + '" class="agent-entity-link">' +
              AIUtils.escapeHtml(block.display_name) + '</a>';
          }
          return AIUtils.escapeHtml(block.content || block.display_name || '');
        }).join('');
      }
      return AIUtils.escapeHtml(msg.content);
    }

    function createMessageEl(msg) {
      if (!tplMessage) return null;
      var clone = tplMessage.content.cloneNode(true);
      var row = clone.querySelector('.agent-message-row');
      var msgEl = clone.querySelector('.agent-message');
      var contentEl = clone.querySelector('[data-role="message-content"]');

      row.classList.add(msg.role === 'user' ? 'agent-message-row--assistant' : 'agent-message-row--user');
      msgEl.classList.add('agent-message--' + msg.role);
      if (contentEl) contentEl.innerHTML = renderMessageContent(msg);

      return clone;
    }

    function createInterruptEl(interrupt) {
      if (!tplInterrupt || !interrupt || !interrupt.resumeToken) return null;
      var clone = tplInterrupt.content.cloneNode(true);
      var card = clone.querySelector('.agent-interrupt');
      var msgEl = clone.querySelector('[data-role="interrupt-message"]');
      var actionsEl = clone.querySelector('[data-role="interrupt-actions"]');
      var approveBtn = clone.querySelector('[data-action="approve"]');
      var rejectBtn = clone.querySelector('[data-action="reject"]');

      card.dataset.resumeToken = interrupt.resumeToken;
      card.dataset.requestPayload = interrupt.requestPayload || '';
      if (msgEl) msgEl.textContent = interrupt.message || '';

      if (actionsEl && tplInterruptAction && interrupt.actions) {
        interrupt.actions.forEach(function (action) {
          var actionClone = tplInterruptAction.content.cloneNode(true);
          var nameEl = actionClone.querySelector('[data-role="action-name"]');
          var descEl = actionClone.querySelector('[data-role="action-desc"]');
          if (nameEl) nameEl.textContent = action.name || '';
          if (descEl) descEl.textContent = action.description ? ' — ' + action.description : '';
          actionsEl.appendChild(actionClone);
        });
      }

      if (approveBtn) {
        approveBtn.onclick = function () {
          resumeWorkflow(interrupt.resumeToken, interrupt.requestPayload, true);
        };
      }
      if (rejectBtn) {
        rejectBtn.onclick = function () {
          resumeWorkflow(interrupt.resumeToken, interrupt.requestPayload, false);
        };
      }

      return clone;
    }

    function renderMessages() {
      if (emptyState) {
        emptyState.style.display = (messages.length === 0 && !isLoading) ? '' : 'none';
      }

      var wrapper = messagesContainer.querySelector('.agent-messages-wrapper');
      if (!wrapper) {
        wrapper = document.createElement('div');
        wrapper.className = 'agent-messages-wrapper';
        messagesContainer.appendChild(wrapper);
      }
      wrapper.innerHTML = '';

      messages.forEach(function (msg) {
        if (msg.interrupt) {
          var interruptEl = createInterruptEl(msg.interrupt);
          if (interruptEl) wrapper.appendChild(interruptEl);
        } else if (msg.content && msg.content.trim()) {
          var msgEl = createMessageEl(msg);
          if (msgEl) wrapper.appendChild(msgEl);
        }
      });

      if (isLoading && tplLoading) {
        wrapper.appendChild(tplLoading.content.cloneNode(true));
      }

      messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function setLoading(on) {
      isLoading = on;
      if (headerIcon) headerIcon.classList.toggle('genai-icon--loading', on);
      renderMessages();
    }

    async function sendMessage() {
      var content = input.value.trim();
      if (!content || isLoading) return;

      if (window.innerWidth <= 768 && !isExpanded) toggleExpand();

      input.value = '';
      input.style.height = 'auto';
      messages.push({role: 'user', content: content});
      setLoading(true);

      try {
        var result = await AIUtils.postJson('agent/chat', {
          message: content,
          thread_id: threadId,
          context: {entity_class: entityClass, entity_id: parseInt(entityId, 10), entity_name: entityName}
        });

        var response = result.response;
        var data = result.data;

        if (response.status === 401) {
          var loginHtml = strings.loginRequired || 'Please log in to use the AI assistant.';
          if (strings.loginUrl) {
            loginHtml = '<a href="' + AIUtils.escapeHtml(strings.loginUrl) + '">' + loginHtml + '</a>';
          }
          messages.push({role: 'assistant', content: loginHtml, isHtml: true});
        } else if (!response.ok) {
          throw new Error(data.message || 'Request failed');
        } else if (data.interrupt) {
          messages.push({
            role: 'assistant',
            content: data.interrupt.message,
            blocks: data.blocks,
            interrupt: data.interrupt
          });
        } else {
          messages.push({role: 'assistant', content: data.message, blocks: data.blocks});
        }
      } catch (error) {
        console.error('[Agent] Chat error:', error);
        messages.push({role: 'assistant', content: strings.error || 'Something went wrong. Please try again.'});
      } finally {
        setLoading(false);
      }
    }

    async function resumeWorkflow(resumeToken, requestPayload, approved) {
      setLoading(true);

      try {
        var result = await AIUtils.postJson('agent/resume', {
          resume_token: resumeToken,
          request_payload: requestPayload,
          approved: approved,
          thread_id: threadId,
          context: {entity_class: entityClass, entity_id: parseInt(entityId, 10), entity_name: entityName}
        });

        var data = result.data;
        if (!result.response.ok) throw new Error(data.message || 'Resume failed');

        messages = messages.filter(function (msg) {
          return !(msg.interrupt && msg.interrupt.resumeToken === resumeToken);
        });

        if (data.interrupt) {
          messages.push({
            role: 'assistant',
            content: data.interrupt.message,
            blocks: data.blocks,
            interrupt: data.interrupt
          });
        } else {
          messages.push({role: 'assistant', content: data.message, blocks: data.blocks});
        }
      } catch (error) {
        console.error('[Agent] Resume error:', error);
        messages.push({role: 'assistant', content: strings.error || 'Something went wrong. Please try again.'});
      } finally {
        setLoading(false);
      }
    }

    function autoResizeTextarea() {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, isExpanded ? 150 : 100) + 'px';
    }

    var titleDefault = document.getElementById('agent-title');
    var titleExpanded = document.getElementById('agent-title-expanded');

    function toggleExpand() {
      isExpanded = !isExpanded;

      if (isExpanded) {
        originalParent = root.parentNode;
        originalNextSibling = root.nextSibling;
        document.body.appendChild(root);

        backdrop = document.createElement('div');
        backdrop.className = 'agent-widget-backdrop agent-widget-backdrop--visible';
        document.body.insertBefore(backdrop, root);
        backdrop.addEventListener('click', toggleExpand);

        root.classList.add('agent-widget--expanded');
        if (titleDefault) titleDefault.style.display = 'none';
        if (titleExpanded) titleExpanded.style.display = '';
        document.body.style.overflow = 'hidden';
      } else {
        root.classList.remove('agent-widget--expanded');
        if (titleDefault) titleDefault.style.display = '';
        if (titleExpanded) titleExpanded.style.display = 'none';
        document.body.style.overflow = '';

        if (backdrop) {
          backdrop.parentNode.removeChild(backdrop);
          backdrop = null;
        }
        if (originalParent) {
          if (originalNextSibling) originalParent.insertBefore(root, originalNextSibling);
          else originalParent.appendChild(root);
        }
      }

      setTimeout(function () {
        input.focus();
      }, 100);
      messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Event listeners
    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    input.addEventListener('input', autoResizeTextarea);

    if (expandBtn) expandBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleExpand();
    });

    var header = root.querySelector('.agent-widget__header');
    if (header) header.addEventListener('click', function () {
      if (window.innerWidth <= 768 && !isExpanded) toggleExpand();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isExpanded) toggleExpand();
    });

    renderMessages();
    console.log('[Agent] Widget ready');
  }

  AIUtils.createWidgetLoader({
    selector: '#agent-root',
    initAttr: 'data-agent-init',
    init: initWidget
  });
})();
