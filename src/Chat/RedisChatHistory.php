<?php

namespace Hudhaifas\AI\Chat;

use NeuronAI\Chat\History\AbstractChatHistory;
use SilverStripe\Core\Injector\Injector;

/**
 * Redis-backed chat history for NeuronAI agents.
 *
 * Stores conversation history in Redis with 1-hour TTL.
 * Automatically handles token counting and history trimming.
 *
 * Requires: hudhaifas/silverstripe-cache-helpers
 * Install it and configure Redis before using this backend.
 */
class RedisChatHistory extends AbstractChatHistory {
    public function __construct(protected string $threadId, int $contextWindow = 50000) {
        $this->assertDependency();
        parent::__construct($contextWindow);
        $this->load();
    }

    /**
     * Load history from Redis cache
     */
    protected function load(): void {
        $key = safe_key("agent_history_{$this->threadId}");
        $json = remember($key, 3600, function () {
            return null;
        });

        if ($json) {
            $messages = json_decode($json, true);
            if (!empty($messages)) {
                $this->history = $this->deserializeMessages($messages);
            }
        }
    }

    /**
     * Save messages to Redis cache
     */
    public function setMessages(array $messages): void {
        $key = safe_key("agent_history_{$this->threadId}");
        $service = Injector::inst()->get('Hudhaifas\CacheHelpers\Services\CacheHelperService');
        $service->forgetKey($key);

        $json = json_encode($this->jsonSerialize());
        remember($key, 3600, function () use ($json) {
            return $json;
        });
    }

    /**
     * Clear history from Redis cache
     */
    protected function clear(): void {
        $key = safe_key("agent_history_{$this->threadId}");
        $service = Injector::inst()->get('Hudhaifas\CacheHelpers\Services\CacheHelperService');
        $service->forgetKey($key);
    }

    private function assertDependency(): void {
        if (!class_exists('Hudhaifas\CacheHelpers\Services\CacheHelperService')) {
            throw new \RuntimeException(
                'RedisChatHistory requires hudhaifas/silverstripe-cache-helpers. ' .
                'Run: composer require hudhaifas/silverstripe-cache-helpers'
            );
        }
    }
}
