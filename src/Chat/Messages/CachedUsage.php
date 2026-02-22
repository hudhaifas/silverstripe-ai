<?php

declare(strict_types=1);

namespace Hudhaifas\AI\Chat\Messages;

use NeuronAI\Chat\Messages\Usage;

/**
 * CachedUsage extends NeuronAI's Usage class to add Anthropic prompt cache metrics.
 *
 * Temporary until https://github.com/neuron-core/neuron-ai/pull/482 is merged,
 * at which point cacheWriteTokens and cacheReadTokens will be on base Usage
 * and this class can be deleted.
 *
 * Tracks two additional token buckets:
 * - cacheWriteTokens: Tokens written to cache on first request (25% cost premium)
 * - cacheReadTokens: Tokens read from cache on subsequent requests (90% cost discount)
 */
class CachedUsage extends Usage {
    public function __construct(
        int        $inputTokens,
        int        $outputTokens,
        public int $cacheWriteTokens = 0,
        public int $cacheReadTokens = 0,
    ) {
        parent::__construct($inputTokens, $outputTokens);
    }
}
