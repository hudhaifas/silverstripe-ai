<?php

namespace Hudhaifas\AI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Redis-backed persistence for NeuronAI HITL workflow state.
 *
 * Stores serialized WorkflowInterrupt objects in Redis with a configurable TTL.
 * Keys are namespaced as: agent_workflow_{workflowId}
 *
 * Requires: hudhaifas/silverstripe-cache-helpers
 * Install it and configure Redis before using this backend.
 */
class RedisPersistence implements PersistenceInterface {
    /**
     * @param int $ttl Seconds to keep the interrupt state (default: 1 hour)
     */
    public function __construct(private int $ttl = 3600) {
        $this->assertDependency();
    }

    public function save(string $workflowId, WorkflowInterrupt $interrupt): void {
        $key = safe_key("agent_workflow_{$workflowId}");

        $service = Injector::inst()->get('Hudhaifas\CacheHelpers\Services\CacheHelperService');
        $service->forgetKey($key);

        $serialized = serialize($interrupt);
        remember($key, $this->ttl, function () use ($serialized) {
            return $serialized;
        });
    }

    public function load(string $workflowId): WorkflowInterrupt {
        $key = safe_key("agent_workflow_{$workflowId}");
        $data = remember($key, $this->ttl, function () {
            return null;
        });

        if (!$data) {
            throw new WorkflowException("No saved workflow found for ID: {$workflowId}.");
        }

        return unserialize($data);
    }

    public function delete(string $workflowId): void {
        $key = safe_key("agent_workflow_{$workflowId}");
        $service = Injector::inst()->get('Hudhaifas\CacheHelpers\Services\CacheHelperService');
        $service->forgetKey($key);
    }

    private function assertDependency(): void {
        if (!class_exists('Hudhaifas\CacheHelpers\Services\CacheHelperService')) {
            throw new \RuntimeException(
                'RedisPersistence requires hudhaifas/silverstripe-cache-helpers. ' .
                'Run: composer require hudhaifas/silverstripe-cache-helpers'
            );
        }
    }
}
