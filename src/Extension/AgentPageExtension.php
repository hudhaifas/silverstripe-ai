<?php

namespace Hudhaifas\AI\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * Extension to inject agent chat assets on pages that include the agent widget.
 *
 * Apply to any PageController that renders AgentWidget.ss.
 */
class AgentPageExtension extends Extension {
    public function onAfterInit(): void {
        Requirements::css('hudhaifas/silverstripe-ai: res/css/agent-widget.css');

        // Load RTL stylesheet if the owner exposes an isRTL() method (e.g. via a site-specific extension)
        if (method_exists($this->owner, 'isRTL') && $this->owner->isRTL()) {
            Requirements::css('hudhaifas/silverstripe-ai: res/css/agent-widget-rtl.css');
        }

        Requirements::javascript('hudhaifas/silverstripe-ai: res/js/agent-widget.js', ['defer' => true]);
    }

    public function canShowAgent(): bool {
        return true;
    }
}
