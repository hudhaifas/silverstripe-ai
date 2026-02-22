/**
 * Agent Chat Widget
 *
 * Vanilla JavaScript implementation for the agent chat sidebar.
 * Mounts to #agent-root element with data attributes for configuration.
 */
(function () {
    'use strict';

    function init() {
        const root = document.getElementById('agent-root');
        console.log('[Agent] Initializing chat widget');

        // Configuration from data attributes
        const entityClass = root.dataset.entityClass;
        const entityId = root.dataset.entityId;
        const entityName = root.dataset.entityName;
        const locale = root.dataset.locale || 'en';
        const isRTL = locale.startsWith('ar');
        const loggedInAttr = root.dataset.loggedIn;
        const isLoggedIn = loggedInAttr && loggedInAttr.trim().toLowerCase() === 'true';

        console.log('[Agent] Entity:', entityClass, 'ID:', entityId, 'Name:', entityName, 'LoggedIn:', loggedInAttr, '->', isLoggedIn);

        // DOM elements
        const messagesContainer = document.getElementById('agent-messages');
        const input = document.getElementById('agent-input');
        const sendBtn = document.getElementById('agent-send');
        const expandBtn = document.getElementById('agent-expand');
        const headerIcon = root.querySelector('.agent-widget__icon');

        if (!messagesContainer || !input || !sendBtn) {
            console.error('[Agent] Missing DOM elements:', {
                messagesContainer: !!messagesContainer,
                input: !!input,
                sendBtn: !!sendBtn
            });
            return;
        }

        console.log('[Agent] DOM elements found, attaching listeners');

        // State
        let isLoading = false;
        let isExpanded = false;
        let messages = [];
        const threadId = crypto.randomUUID();
        let backdrop = null;

        // Localized strings
        const strings = {
            en: {
                error: 'Something went wrong. Please try again.',
                confirm: 'Confirm',
                cancel: 'Cancel',
                cancelled: 'Changes cancelled.',
                confirmed: 'Changes confirmed.',
                loginRequired: 'Please <a href="/Security/login">log in</a> to use the AI assistant.'
            },
            ar: {
                error: 'حدث خطأ. يرجى المحاولة مرة أخرى.',
                confirm: 'تأكيد',
                cancel: 'إلغاء',
                cancelled: 'تم إلغاء التغييرات.',
                confirmed: 'تم تأكيد التغييرات.',
                loginRequired: 'يرجى <a href="/Security/login">تسجيل الدخول</a> لاستخدام المساعد الذكي.'
            }
        };

        /**
         * Get localized string with placeholder support
         */
        function t(key, params) {
            const lang = isRTL ? 'ar' : 'en';
            let str = strings[lang][key] || strings.en[key] || key;

            // Replace placeholders like {count}, {persons}
            if (params) {
                Object.keys(params).forEach(function (k) {
                    str = str.replace('{' + k + '}', params[k]);
                });
            }

            return str;
        }

        /**
         * Escape HTML to prevent XSS
         */
        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        /**
         * Parse entity references in content (fallback when no blocks array is present).
         * Converts [Name](entity_type:{ID}) markdown to plain text — no URL available
         * without the resolved link from the backend, so we strip the markdown syntax.
         */
        function parseContent(content) {
            if (!content) return '';

            const escaped = escapeHtml(content);

            // Strip entity reference markdown — render display name only (no URL in raw content)
            return escaped.replace(
                /\[([^\]]+)\]\([a-z_]+:\d+\)/g,
                '$1'
            );
        }

        /**
         * Render message content from blocks array (preferred) or raw content.
         *
         * Block types emitted by AgentService.parseResponseBlocks():
         *   { type: 'text', content: '...' }
         *   { type: 'entity_reference', entity_type: 'person', entity_id: 123, display_name: '...' }
         */
        function renderMessageContent(msg) {
            // If message contains pre-rendered HTML (like login prompt)
            if (msg.isHtml) {
                return msg.content;
            }
            // If we have blocks from the API, use them for proper links
            if (msg.blocks && msg.blocks.length > 0) {
                return msg.blocks.map(function (block) {
                    if (block.type === 'entity_reference') {
                        // Use the resolved link from the backend (ManageableDataObject::getObjectLink())
                        // so this works for any DataObject type, not just genealogy people
                        var href = block.link || '';
                        if (href) {
                            return '<a href="' + escapeHtml(href) + '" class="agent-entity-link">' +
                                escapeHtml(block.display_name) + '</a>';
                        }
                        // No link available — render as plain text
                        return escapeHtml(block.display_name || '');
                    }
                    // type === 'text' (or any unknown block)
                    return escapeHtml(block.content || '');
                }).join('');
            }
            // Fallback to parsing raw content
            return parseContent(msg.content);
        }

        /**
         * Render all messages to the container
         */
        function renderMessages() {
            const emptyState = document.getElementById('agent-empty-state');

            // Hide empty state when there are messages
            if (emptyState) {
                emptyState.style.display = (messages.length === 0 && !isLoading) ? '' : 'none';
            }

            // Build messages HTML
            let html = messages.map(function (msg) {
                // If message has a pending interrupt, render the approval card
                if (msg.interrupt) {
                    return renderInterruptCard(msg.interrupt);
                }

                // Skip messages with empty content
                if (!msg.content || msg.content.trim() === '') {
                    return '';
                }

                const roleClass = 'agent-message--' + msg.role;
                const renderedContent = renderMessageContent(msg);

                // User on right, assistant on left
                const alignClass = msg.role === 'user' ? 'agent-message-row--assistant' : 'agent-message-row--user';

                return '<div class="agent-message-row ' + alignClass + '">' +
                    '<div class="agent-message ' + roleClass + '">' +
                    renderedContent +
                    '</div>' +
                    '</div>';
            }).join('');

            // Add loading indicator if loading
            if (isLoading) {
                html += '<div class="agent-message-row agent-message-row--user">' +
                    '<div class="agent-message agent-message--loading">' +
                    '<i class="fas fa-spinner fa-spin"></i>' +
                    '</div>' +
                    '</div>';
            }

            // Get or create messages wrapper (preserves empty state element)
            let messagesWrapper = messagesContainer.querySelector('.agent-messages-wrapper');
            if (!messagesWrapper) {
                messagesWrapper = document.createElement('div');
                messagesWrapper.className = 'agent-messages-wrapper';
                messagesContainer.appendChild(messagesWrapper);
            }
            messagesWrapper.innerHTML = html;
            messagesContainer.scrollTop = messagesContainer.scrollHeight;

            // Attach event listeners to interrupt approval buttons
            attachInterruptListeners();
        }

        /**
         * Send a message to the agent API
         */
        async function sendMessage() {
            console.log('[Agent] sendMessage called');
            const content = input.value.trim();

            if (!content) {
                console.log('[Agent] Empty message, ignoring');
                return;
            }
            if (isLoading) {
                console.log('[Agent] Already loading, ignoring');
                return;
            }

            // On mobile, auto-expand when sending a message
            if (window.innerWidth <= 768 && !isExpanded) {
                toggleExpand();
            }

            input.value = '';
            input.style.height = 'auto';

            messages.push({role: 'user', content: content});

            console.log('[Agent] Sending message:', content);

            isLoading = true;
            if (headerIcon) headerIcon.classList.add('agent-widget__icon--loading');
            renderMessages();

            try {
                console.log('[Agent] Making fetch request to /api/agent/chat');
                const response = await fetch('/api/agent/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        message: content,
                        thread_id: threadId,
                        context: {
                            entity_class: entityClass,
                            entity_id: parseInt(entityId, 10),
                            entity_name: entityName
                        }
                    })
                });

                console.log('[Agent] Response status:', response.status);
                const data = await response.json();
                console.log('[Agent] Response data:', data);

                if (response.status === 401) {
                    messages.push({role: 'assistant', content: t('loginRequired'), isHtml: true});
                } else if (!response.ok) {
                    throw new Error(data.message || 'Request failed with status ' + response.status);
                } else if (data.interrupt) {
                    // HITL: agent wants approval before executing a write tool
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
                messages.push({role: 'assistant', content: t('error')});
            } finally {
                isLoading = false;
                if (headerIcon) headerIcon.classList.remove('agent-widget__icon--loading');
                renderMessages();
            }
        }

        /**
         * Render an interrupt approval card.
         *
         * Displays the pending tool action(s) with Approve / Reject buttons.
         * The resumeToken and requestPayload are stored as data attributes so
         * the event listeners can pass them back to /api/agent/resume.
         *
         * @param {object} interrupt - The interrupt object from the API response.
         * @returns {string} HTML string for the approval card.
         */
        function renderInterruptCard(interrupt) {
            if (!interrupt || !interrupt.resumeToken) return '';

            const actionsHtml = (interrupt.actions || []).map(function (action) {
                return '<div class="agent-interrupt__action">' +
                    '<strong>' + escapeHtml(action.name) + '</strong>' +
                    (action.description ? '<span class="agent-interrupt__action-desc"> — ' + escapeHtml(action.description) + '</span>' : '') +
                    '</div>';
            }).join('');

            return '<div class="agent-interrupt" ' +
                'data-resume-token="' + escapeHtml(interrupt.resumeToken) + '" ' +
                'data-request-payload="' + escapeHtml(interrupt.requestPayload) + '">' +
                '<div class="agent-interrupt__header">' + escapeHtml(interrupt.message) + '</div>' +
                (actionsHtml ? '<div class="agent-interrupt__actions-list">' + actionsHtml + '</div>' : '') +
                '<div class="agent-interrupt__buttons">' +
                '<button class="btn agent-interrupt__reject" type="button">' + t('cancel') + '</button>' +
                '<button class="btn agent-interrupt__approve" type="button">' + t('confirm') + '</button>' +
                '</div>' +
                '</div>';
        }

        /**
         * Attach approve/reject listeners to interrupt cards after each render.
         */
        function attachInterruptListeners() {
            document.querySelectorAll('.agent-interrupt__approve').forEach(function (btn) {
                const card = btn.closest('.agent-interrupt');
                btn.onclick = function () {
                    resumeWorkflow(card.dataset.resumeToken, card.dataset.requestPayload, true);
                };
            });
            document.querySelectorAll('.agent-interrupt__reject').forEach(function (btn) {
                const card = btn.closest('.agent-interrupt');
                btn.onclick = function () {
                    resumeWorkflow(card.dataset.resumeToken, card.dataset.requestPayload, false);
                };
            });
        }

        /**
         * Resume an interrupted workflow after the user approves or rejects.
         *
         * POSTs to /api/agent/resume with the opaque resumeToken, the serialized
         * ApprovalRequest payload, and the user's boolean decision.
         * On success the interrupt card is removed and the LLM's final response
         * is appended to the conversation.
         *
         * @param {string}  resumeToken    - Opaque token from the WorkflowInterrupt.
         * @param {string}  requestPayload - JSON-encoded ApprovalRequest from the interrupt.
         * @param {boolean} approved       - Whether the user approved the pending action.
         */
        async function resumeWorkflow(resumeToken, requestPayload, approved) {
            isLoading = true;
            if (headerIcon) headerIcon.classList.add('agent-widget__icon--loading');
            renderMessages();

            try {
                console.log('[Agent] Resuming workflow, approved:', approved);
                const response = await fetch('/api/agent/resume', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        resume_token: resumeToken,
                        request_payload: requestPayload,
                        approved: approved,
                        thread_id: threadId,
                        context: {
                            entity_class: entityClass,
                            entity_id: parseInt(entityId, 10),
                            entity_name: entityName
                        }
                    })
                });

                const data = await response.json();
                console.log('[Agent] Resume response:', data);

                if (!response.ok) {
                    throw new Error(data.message || 'Resume failed with status ' + response.status);
                }

                // Remove the interrupt message that was just resolved
                messages = messages.filter(function (msg) {
                    return !(msg.interrupt && msg.interrupt.resumeToken === resumeToken);
                });

                if (data.interrupt) {
                    // Chained interrupt — agent wants approval for another action before finishing
                    messages.push({
                        role: 'assistant',
                        content: data.interrupt.message,
                        blocks: data.blocks,
                        interrupt: data.interrupt
                    });
                } else {
                    // No further interrupts — append the LLM's final response
                    messages.push({role: 'assistant', content: data.message, blocks: data.blocks});
                }

            } catch (error) {
                console.error('[Agent] Resume error:', error);
                messages.push({role: 'assistant', content: t('error')});
            } finally {
                isLoading = false;
                if (headerIcon) headerIcon.classList.remove('agent-widget__icon--loading');
                renderMessages();
            }
        }

        /**
         * Auto-resize textarea based on content
         */
        function autoResizeTextarea() {
            input.style.height = 'auto';
            const maxHeight = isExpanded ? 150 : 100;
            input.style.height = Math.min(input.scrollHeight, maxHeight) + 'px';
        }

        /**
         * Toggle expanded/collapsed state
         */
        let originalParent = null;
        let originalNextSibling = null;
        const titleDefault = document.getElementById('agent-title');
        const titleExpanded = document.getElementById('agent-title-expanded');

        function toggleExpand() {
            isExpanded = !isExpanded;

            if (isExpanded) {
                // Store original position
                originalParent = root.parentNode;
                originalNextSibling = root.nextSibling;

                // Move sidebar to body for proper full-screen positioning
                document.body.appendChild(root);

                // Create backdrop
                backdrop = document.createElement('div');
                backdrop.className = 'agent-widget-backdrop agent-widget-backdrop--visible';
                document.body.insertBefore(backdrop, root);

                // Expand sidebar
                root.classList.add('agent-widget--expanded');

                // Switch titles
                if (titleDefault) titleDefault.style.display = 'none';
                if (titleExpanded) titleExpanded.style.display = '';

                // Prevent body scroll
                document.body.style.overflow = 'hidden';

                // Click backdrop to collapse
                backdrop.addEventListener('click', toggleExpand);

                // Update button title
                if (expandBtn) {
                    expandBtn.title = isRTL ? 'تصغير' : 'Collapse';
                }
            } else {
                // Remove expanded class
                root.classList.remove('agent-widget--expanded');

                // Switch titles back
                if (titleDefault) titleDefault.style.display = '';
                if (titleExpanded) titleExpanded.style.display = 'none';

                // Restore body scroll
                document.body.style.overflow = '';

                // Remove backdrop
                if (backdrop) {
                    backdrop.parentNode.removeChild(backdrop);
                    backdrop = null;
                }

                // Move sidebar back to original position
                if (originalParent) {
                    if (originalNextSibling) {
                        originalParent.insertBefore(root, originalNextSibling);
                    } else {
                        originalParent.appendChild(root);
                    }
                }

                // Update button title
                if (expandBtn) {
                    expandBtn.title = isRTL ? 'توسيع' : 'Expand';
                }
            }

            // Focus input after toggle
            setTimeout(function () {
                input.focus();
            }, 100);

            // Scroll to bottom
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Event Listeners
        sendBtn.addEventListener('click', function () {
            console.log('[Agent] Send button clicked');
            sendMessage();
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                console.log('[Agent] Enter key pressed');
                e.preventDefault();
                sendMessage();
            }
        });

        input.addEventListener('input', autoResizeTextarea);

        // Expand button listener
        if (expandBtn) {
            expandBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                console.log('[Agent] Expand button clicked');
                toggleExpand();
            });
        }

        // On mobile, clicking the header expands to full screen
        const header = root.querySelector('.agent-widget__header');
        if (header) {
            header.addEventListener('click', function (e) {
                // Only trigger on mobile (when messages are hidden)
                if (window.innerWidth <= 768 && !isExpanded) {
                    console.log('[Agent] Header clicked on mobile, expanding');
                    toggleExpand();
                }
            });
        }

        // ESC key to collapse
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isExpanded) {
                toggleExpand();
            }
        });

        // Render initial empty state
        renderMessages();

        console.log('[Agent] Chat widget ready');
    }

    // Initialize when DOM is ready
    let retryCount = 0;
    const MAX_RETRIES = 50; // 50 * 200ms = 10 seconds max wait

    function tryInit() {
        const root = document.getElementById('agent-root');
        if (root) {
            init();
        } else if (retryCount < MAX_RETRIES) {
            // Element not found yet, try again (likely loading via AJAX)
            retryCount++;
            if (retryCount === 1 || retryCount % 10 === 0) {
                console.log('[Agent] Waiting for sidebar to load...');
            }
            setTimeout(tryInit, 200);
        } else {
            console.warn('[Agent] Sidebar element not found after maximum retries');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryInit);
    } else {
        tryInit();
    }

})();
