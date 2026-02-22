<?php

namespace Hudhaifas\AI\Chat;

use NeuronAI\Chat\History\AbstractChatHistory;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * SilverStripe-cache-backed chat history.
 *
 * Uses the PSR-16 cache pool registered as 'AgentCache' in Injector.
 * Works with any SS cache backend (filesystem, APCu, Redis, Memcached).
 * No external dependencies required.
 *
 * To use Redis, bind 'AgentCache' to a Redis-backed PSR-16 adapter in YAML.
 */
class SilverStripeCacheHistory extends AbstractChatHistory {
    public function __construct(protected string $threadId, int $contextWindow = 50000) {
        parent::__construct($contextWindow);
        $this->load();
    }

    protected function load(): void {
        $json = $this->cache()->get($this->cacheKey());
        if ($json) {
            $messages = json_decode($json, true);
            if (!empty($messages)) {
                $this->history = $this->deserializeMessages($messages);
            }
        }
    }

    public function setMessages(array $messages): void {
        $this->cache()->set($this->cacheKey(), json_encode($this->jsonSerialize()), 3600);
    }

    protected function clear(): void {
        $this->cache()->delete($this->cacheKey());
    }

    private function cacheKey(): string {
        return 'agent_history_' . md5($this->threadId);
    }

    private function cache(): CacheInterface {
        return Injector::inst()->get('AgentCache');
    }
}
