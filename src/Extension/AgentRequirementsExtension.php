<?php

namespace Hudhaifas\AI\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * AgentRequirementsExtension
 *
 * Injects agent chatbot widget assets.
 * Apply this extension to the PageController to enable the agent feature.
 *
 * YAML:
 *   PeoplePageController:
 *     extensions:
 *       - Hudhaifas\AI\Extension\AgentRequirementsExtension
 */
class AgentRequirementsExtension extends Extension {
    public function onAfterInit(): void {
        Requirements::javascript('hudhaifas/silverstripe-ai: res/js/ai-utils.js', ['defer' => true]);
        Requirements::javascript('hudhaifas/silverstripe-ai: res/js/agent-widget.js', ['defer' => true]);

        Requirements::css('hudhaifas/silverstripe-ai: res/css/agent-widget.css');
        if (method_exists($this->owner, 'isRTL') && $this->owner->isRTL()) {
            Requirements::css('hudhaifas/silverstripe-ai: res/css/agent-widget-rtl.css');
        }
    }
}
