<?php

namespace Hudhaifas\AI\Extension;

use Hudhaifas\AI\Model\AIUsageLog;
use SilverStripe\Core\Extension;

/**
 * ContentExtension
 *
 * Abstract extension for AI content generation on any DataObject.
 * Apply this to your model and implement the four abstract methods
 * to enable the Content Widget.
 *
 * Example:
 *   class ProductContentExtension extends ContentExtension {
 *       public function getStaticInstructions(): string { ... }
 *       public function getDynamicContext(): string { ... }
 *       public function getContentField(): string { return 'Description'; }
 *       public function saveContent(string $content): void { ... }
 *   }
 */
abstract class ContentExtension extends Extension {
    private static $db = [
        'IsAIGenerated' => 'Boolean',
    ];
    private static $has_many = [
        'AIUsageLogs' => AIUsageLog::class . '.Entity',
    ];

    /**
     * Static system prompt for the LLM.
     *
     * Defines what kind of content to generate (tone, length, format).
     * Cached by Anthropic for cost savings. Keep stable across requests.
     */
    abstract public function getStaticInstructions(): string;

    /**
     * Dynamic entity context for the LLM.
     *
     * Returns the entity's data that the LLM uses to generate content.
     * Called fresh on each request. Keep concise.
     */
    abstract public function getDynamicContext(): string;

    /**
     * Persist the approved/edited content to the entity.
     *
     * Called by PersistContentNode after user approval.
     * Typically sets the content field and IsAIGenerated flag, then writes.
     */
    abstract public function saveContent(string $content): void;

    /**
     * The DB field name that stores the generated content.
     *
     * Used by the Content Widget template to display existing content.
     */
    abstract public function getContentField(): string;
}
