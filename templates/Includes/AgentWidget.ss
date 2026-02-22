<div class="agent-widget"
     id="agent-root"
     data-entity-class="$ClassName"
     data-entity-id="$ID"
     data-entity-name="$Title"
     data-locale="$i18nLocale"
     data-logged-in="<% if $CurrentMember %>true<% else %>false<% end_if %>">

    <div class="agent-widget__header d-flex align-items-center gap-2">
        <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" class="agent-widget__icon" aria-hidden="true">
            <path d="M6.15 10.365 8 15.005l1.86-4.64 4.64-1.86-4.64-1.85L8 2.005l-1.85 4.65-4.65 1.85 4.65 1.86Z" />
            <path d="M2.38 4.915c.02.05.07.08.12.08.05 0 .12-.08.12-.08l.66-1.64 1.64-.66a.13.13 0 0 0 .08-.12c0-.05-.08-.12-.08-.12l-1.64-.66-.66-1.64c-.04-.1-.2-.1-.24 0l-.66 1.64-1.64.66a.13.13 0 0 0 .08-.12c0 .05.08.12.08.12l1.64.66.66 1.64Z" />
        </svg>
        <span class="agent-widget__title flex-grow-1" id="agent-title"><%t AgentWidget.TITLE 'AI Assistant' %></span>
        <span class="agent-widget__title-expanded flex-grow-1" id="agent-title-expanded" style="display: none;"><%t AgentWidget.TITLE_EXPANDED 'Assistant — {name}' name=$Title %></span>
        <button type="button" class="agent-widget__expand" id="agent-expand" title="<%t AgentWidget.EXPAND 'Expand' %>">
            <i class="fas fa-expand"></i>
        </button>
    </div>

    <div class="agent-widget__messages" id="agent-messages">
        <div class="agent-empty-state" id="agent-empty-state">
            <div class="agent-empty-state__icon">
                <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" width="48" height="48" aria-hidden="true">
                    <path d="M6.15 10.365 8 15.005l1.86-4.64 4.64-1.86-4.64-1.85L8 2.005l-1.85 4.65-4.65 1.85 4.65 1.86Z" />
                    <path d="M2.38 4.915c.02.05.07.08.12.08.05 0 .12-.08.12-.08l.66-1.64 1.64-.66a.13.13 0 0 0 .08-.12c0-.05-.08-.12-.08-.12l-1.64-.66-.66-1.64c-.04-.1-.2-.1-.24 0l-.66 1.64-1.64.66a.13.13 0 0 0 .08.12c0 .05.08.12.08.12l1.64.66.66 1.64Z" />
                </svg>
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
</div>
