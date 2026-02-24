<div class="agent-widget"
     id="agent-root"
     data-entity-class="$ClassName"
     data-entity-id="$ID"
     data-entity-name="$Title"
     data-locale="$i18nLocale"
     data-logged-in="<% if $CurrentMember %>true<% else %>false<% end_if %>">

    <div class="agent-widget__header d-flex align-items-center gap-2">
        <span class="genai-icon"><% include AIIcon %></span>
        <span class="agent-widget__title flex-grow-1"
              id="agent-title"><%t AgentWidget.TITLE 'AI Assistant' %></span>
        <span class="agent-widget__title-expanded flex-grow-1" id="agent-title-expanded"
              style="display: none;"><%t AgentWidget.TITLE_EXPANDED 'Assistant — {name}' name=$Title %></span>
        <button type="button" class="agent-widget__expand" id="agent-expand"
                title="<%t AgentWidget.EXPAND 'Expand' %>">
            <i class="fas fa-expand"></i>
        </button>
    </div>

    <div class="agent-widget__messages" id="agent-messages">
        <div class="agent-empty-state" id="agent-empty-state">
            <div class="agent-empty-state__icon">
                <span class="genai-icon genai-icon--lg"><% include AIIcon %></span>
            </div>
            <div class="agent-empty-state__text"><%t AgentWidget.EMPTY_STATE 'Ask me about {name}.' name=$Title %></div>
        </div>
    </div>

    <div class="agent-widget__input d-flex gap-2">
        <textarea id="agent-input"
                  class="flex-grow-1"
                  placeholder="<%t AgentWidget.PLACEHOLDER 'Ask a question...' %>"
                  rows="1"></textarea>
        <button id="agent-send" class="btn btn-primary agent-widget__send" type="button">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>

    <%-- Hidden templates for JS to clone --%>
    <template id="agent-tpl-message">
        <div class="agent-message-row">
            <div class="agent-message" data-role="message-content"></div>
        </div>
    </template>

    <template id="agent-tpl-loading">
        <div class="agent-message-row agent-message-row--user">
            <div class="agent-message agent-message--loading">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
        </div>
    </template>

    <template id="agent-tpl-interrupt">
        <div class="agent-interrupt">
            <div class="agent-interrupt__header" data-role="interrupt-message"></div>
            <div class="agent-interrupt__actions-list" data-role="interrupt-actions"></div>
            <div class="agent-interrupt__buttons">
                <button class="btn agent-interrupt__reject" type="button" data-action="reject">
                    <%t AgentWidget.CANCEL 'Cancel' %>
                </button>
                <button class="btn agent-interrupt__approve" type="button" data-action="approve">
                    <%t AgentWidget.CONFIRM 'Confirm' %>
                </button>
            </div>
        </div>
    </template>

    <template id="agent-tpl-interrupt-action">
        <div class="agent-interrupt__action">
            <strong data-role="action-name"></strong>
            <span class="agent-interrupt__action-desc" data-role="action-desc"></span>
        </div>
    </template>

    <%-- Localized strings for JS --%>
    <script type="application/json" id="agent-strings">
        {
            "error": "<%t AgentWidget.ERROR 'Something went wrong. Please try again.' %>",
            "loginRequired": "<%t AgentWidget.LOGIN_REQUIRED 'Please log in to use the AI assistant.' %>",
            "loginUrl": "/Security/login"
        }
    </script>
</div>
