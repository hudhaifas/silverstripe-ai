<?php

namespace Hudhaifas\AI\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

/**
 * ContentRequirementsExtension
 *
 * Injects content generation widget assets.
 * Apply this extension to enable the content feature.
 *
 * YAML:
 *   PeoplePageController:
 *     extensions:
 *       - Hudhaifas\AI\Extension\ContentRequirementsExtension
 */
class ContentRequirementsExtension extends Extension {
    public function onAfterInit(): void {
        Requirements::javascript('hudhaifas/silverstripe-ai: res/js/ai-utils.js', ['defer' => true]);
        Requirements::javascript('hudhaifas/silverstripe-ai: res/js/content-widget.js', ['defer' => true]);

        Requirements::css('hudhaifas/silverstripe-ai: res/css/content-widget.css');
    }
}
