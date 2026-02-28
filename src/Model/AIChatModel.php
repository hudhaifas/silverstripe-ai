<?php

namespace Hudhaifas\AI\Model;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DB;

/**
 * AIChatModel
 *
 * Represents a chat/generation LLM (e.g. GPT-4o, Claude Sonnet).
 * Extends AIModel with chat-specific fields: context window, tool support,
 * performance tier, and credit policy.
 *
 * Used by DataObjectAgent and ContentService.
 */
class AIChatModel extends AIModel {
    private static string $table_name = 'AIChatModel';
    private static array $db = [
        'ContextWindow' => 'Int',
        'MaxOutputTokens' => 'Int',
        'SupportsTools' => 'Boolean',
        'PerformanceTier' => 'Enum("fast,balanced,quality","balanced")',
        'AllowedForFreeCredits' => 'Boolean',
        'AllowedForPurchasedCredits' => 'Boolean',
    ];
    private static array $defaults = [
        'ContextWindow' => 128000,
        'MaxOutputTokens' => 4096,
        'SupportsTools' => true,
        'PerformanceTier' => 'balanced',
        'AllowedForFreeCredits' => true,
        'AllowedForPurchasedCredits' => true,
    ];

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        $fields->addFieldToTab('Root.Capabilities',
            NumericField::create('ContextWindow', 'Context Window (tokens)')
                ->setDescription('Maximum context window size in tokens')
        );
        $fields->addFieldToTab('Root.Capabilities',
            NumericField::create('MaxOutputTokens', 'Max Output Tokens')
                ->setDescription('Maximum output tokens per request')
        );
        $fields->addFieldToTab('Root.Capabilities',
            CheckboxField::create('SupportsTools', 'Supports Tools')
                ->setDescription('Whether this model supports function calling/tools')
        );
        $fields->addFieldToTab('Root.Capabilities',
            DropdownField::create('PerformanceTier', 'Performance Tier', [
                'fast' => 'Fast',
                'balanced' => 'Balanced',
                'quality' => 'Quality',
            ])
        );
        $fields->addFieldToTab('Root.CreditPolicy',
            CheckboxField::create('AllowedForFreeCredits', 'Allowed for Free Credits')
                ->setDescription('Can this model be used with free monthly credits?')
        );
        $fields->addFieldToTab('Root.CreditPolicy',
            CheckboxField::create('AllowedForPurchasedCredits', 'Allowed for Purchased Credits')
                ->setDescription('Can this model be used with purchased credits?')
        );

        return $fields;
    }

    public function getCapabilities(): array {
        return [
            'context_window' => (int)$this->ContextWindow,
            'max_output_tokens' => (int)$this->MaxOutputTokens,
            'supports_tools' => (bool)$this->SupportsTools,
            'performance_tier' => $this->PerformanceTier,
        ];
    }

    public function canUseWithFreeCredits(): bool {
        return (bool)$this->AllowedForFreeCredits;
    }

    public function canUseWithPurchasedCredits(): bool {
        return (bool)$this->AllowedForPurchasedCredits;
    }

    /**
     * Seed default chat model records on dev/build.
     * Pricing: https://openai.com/api/pricing/ and https://docs.anthropic.com/en/docs/about-claude/models
     */
    public function requireDefaultRecords(): void {
        parent::requireDefaultRecords();

        $models = [
            // name, displayName, inputCost, outputCost, cacheWriteCost, cacheReadCost, provider, contextWindow, maxOutputTokens, supportsTools
            // OpenAI — no prompt caching (cache costs = 0)
            // Pricing: https://openai.com/api/pricing/
            ['gpt-4.1-nano', 'GPT-4.1 Nano', 0.10, 0.40, 0, 0, 'OpenAI', 1047576, 32768, true],
            ['gpt-4o-mini', 'GPT-4o Mini', 0.15, 0.60, 0, 0, 'OpenAI', 128000, 4096, true],
            ['gpt-4.1-mini', 'GPT-4.1 Mini', 0.40, 1.60, 0, 0, 'OpenAI', 1047576, 32768, true],
            ['gpt-4o', 'GPT-4o', 2.50, 10.0, 0, 0, 'OpenAI', 128000, 4096, true],
            ['gpt-4.1', 'GPT-4.1', 2.00, 8.00, 0, 0, 'OpenAI', 1047576, 32768, true],
            // Anthropic — cache write = 25% premium, cache read = 90% discount
            // Pricing: https://docs.anthropic.com/en/docs/about-claude/models
            ['claude-haiku-3-5-20241022', 'Claude Haiku 3.5', 0.80, 4.00, 1.00, 0.08, 'Anthropic', 200000, 8192, true],
            ['claude-haiku-4-5-20251001', 'Claude Haiku 4.5', 1.00, 5.00, 1.25, 0.10, 'Anthropic', 200000, 8192, true],
            ['claude-sonnet-4-5-20250929', 'Claude Sonnet 4.5', 3.00, 15.0, 3.75, 0.30, 'Anthropic', 200000, 8192, true],
            ['claude-sonnet-4-20250514', 'Claude Sonnet 4', 3.00, 15.0, 3.75, 0.30, 'Anthropic', 200000, 8192, true],
        ];

        foreach ($models as [$name, $displayName, $inputCost, $outputCost, $cacheWriteCost, $cacheReadCost, $provider, $contextWindow, $maxOutputTokens, $supportsTools]) {
            if (self::get()->filter('Name', $name)->exists()) {
                continue;
            }
            $model = self::create();
            $model->Name = $name;
            $model->DisplayName = $displayName;
            $model->Provider = $provider;
            $model->InputCostPer1M = $inputCost;
            $model->OutputCostPer1M = $outputCost;
            $model->CacheWriteCostPer1M = $cacheWriteCost;
            $model->CacheReadCostPer1M = $cacheReadCost;
            $model->ContextWindow = $contextWindow;
            $model->MaxOutputTokens = $maxOutputTokens;
            $model->SupportsTools = $supportsTools;
            $model->write();
            DB::alteration_message("Created AI Chat Model: $displayName ($provider)", 'created');
        }
    }
}
