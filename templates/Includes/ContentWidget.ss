<div data-role="content-widget-root"
     class="content-widget<% if $Borderless %> content-widget--borderless<% end_if %>"
     data-entity-id="$EntityID"
     data-entity-class="$EntityClass"
     data-model-id="$ModelID"
     data-field="$ContentField">

    <%-- ── View mode (always rendered, hidden when empty) ──────────────── --%>
    <div data-role="content-widget-display"<% if not $ContentFieldValue %> style="display:none;"<% end_if %>>
        <div data-role="content-widget-text">
            <% if $ContentFieldHTML %>$ContentFieldHTML.RAW<% end_if %>
        </div>

        <% if $canEdit %>
            <div class="row mt-2">
                <div class="col">
                    <div class="ai-generated-badge d-flex align-items-center"
                         data-role="ai-disclaimer"
                         <% if not $IsAIGenerated %>style="display:none;"<% end_if %>>
                        <span class="genai-icon me-2"><% include AIIcon %></span>
                        <%t ContentWidget.AI_DISCLAIMER 'This content was generated using AI' %>
                    </div>
                </div>
                <div class="col-auto">
                    <button data-action="show-edit"
                            class="btn btn-outline-secondary btn-sm"
                            title="<%t Genealogy.EDIT 'Edit' %>">
                        <i class="fa fa-pencil" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        <% else %>
            <div class="row mt-2">
                <div class="col">
                    <div class="ai-generated-badge d-flex align-items-center"
                         data-role="ai-disclaimer"
                         <% if not $IsAIGenerated %>style="display:none;"<% end_if %>>
                        <span class="genai-icon me-2"><% include AIIcon %></span>
                        <%t ContentWidget.AI_DISCLAIMER 'This content was generated using AI' %>
                    </div>
                </div>
            </div>
        <% end_if %>
    </div>

    <% if $canEdit %>
        <%-- ── Edit form (hidden when content exists, visible when empty) ── --%>
        <div data-role="content-widget-edit-form"<% if $ContentFieldValue %> style="display:none;"<% end_if %>>
            <div class="d-flex justify-content-end mb-2">
                <div class="d-flex" style="gap: 0.75rem;">
                    <button class="btn btn-ai-generate btn-sm d-flex align-items-center"
                            data-action="ai-generate">
                        <span class="genai-icon me-1"><% include AIIcon %></span>
                        <%t ContentWidget.GENERATE_CONTENT 'إنشاء باستخدام AI' %>
                    </button>
                    <% if $ContentFieldValue %>
                        <button data-action="hide-edit"
                                class="btn btn-outline-secondary btn-sm"
                                title="<%t Genealogy.CANCEL 'Cancel' %>">
                            <i class="fa fa-times" aria-hidden="true"></i>
                        </button>
                    <% end_if %>
                </div>
            </div>
            <div data-role="ai-loading" style="display:none;" class="mb-2">
                <span class="genai-icon me-2"><i class="fas fa-spinner fa-spin"></i></span>
                <%t ContentWidget.LOADING 'Generating... Please wait.' %>
            </div>
            <textarea data-role="content-widget-textarea"
                      class="form-control content-widget-textarea"
                      rows="10"
                      placeholder="<%t ContentWidget.PLACEHOLDER 'Write or generate content...' %>"
                      aria-label="<%t ContentWidget.PLACEHOLDER 'Write or generate content...' %>">$ContentFieldValue</textarea>
            <div class="d-flex justify-content-end mt-2" style="gap: 0.5rem;">
                <% if $ContentFieldValue %>
                    <button data-action="hide-edit"
                            class="btn btn-outline-secondary btn-sm">
                        <%t Genealogy.CANCEL 'Cancel' %>
                    </button>
                <% end_if %>
                <button data-action="manual-save"
                        class="btn btn-primary btn-sm">
                    <%t Genealogy.SAVE 'Save' %>
                </button>
            </div>
            <div class="mt-2 small ai-hint d-flex align-items-center"
                 data-role="ai-hint" style="display:none;">
                <span class="genai-icon me-1"><% include AIIcon %></span>
                <%t ContentWidget.HINT 'Content will be generated based on available data. Please review and edit before saving.' %>
            </div>
        </div>
    <% end_if %>

</div>
