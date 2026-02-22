<?php

namespace Hudhaifas\AI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * SilverStripe-cache-backed HITL workflow persistence.
 *
 * Uses the PSR-16 cache pool registered as 'AgentCache' in Injector.
 * Works with any SS cache backend (filesystem, APCu, Redis, Memcached).
 * No external dependencies required.
 */
class SilverStripeCachePersistence implements PersistenceInterface {
    public function __construct(private int $ttl = 3600) {}

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void {
        $this->cache()->set($this->cacheKey($workflowId), serialize($interrupt), $this->ttl);
    }

    public function load(string $workflowId): WorkflowInterrupt {
        $data = $this->cache()->get($this->cacheKey($workflowId));
        if (!$data) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }
        return unserialize($data);
    }

    public function delete(string $workflowId): void {
        $this->cache()->delete($this->cacheKey($workflowId));
    }

    private function cacheKey(string $workflowId): string {
        return 'agent_workflow_' . md5($workflowId);
    }

    private function cache(): CacheInterface {
        return Injector::inst()->get('AgentCache');
    }
}
