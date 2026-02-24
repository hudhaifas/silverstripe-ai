<?php

namespace Hudhaifas\AI\Workflow\Nodes;

use Hudhaifas\AI\Workflow\ContentWorkflow;
use Hudhaifas\AI\Workflow\Events\ContentApprovedEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Handles ContentApprovedEvent: persists content via the entity's
 * ContentExtension and returns StopEvent.
 */
class PersistContentNode extends Node {
    public function __invoke(ContentApprovedEvent $event, WorkflowState $state): Event {
        $logger = Injector::inst()->get(LoggerInterface::class);

        $entityClass = $state->get(ContentWorkflow::STATE_ENTITY_CLASS);
        $entityId = $state->get(ContentWorkflow::STATE_ENTITY_ID);
        $content = $state->get(ContentWorkflow::STATE_CONTENT, '');

        $entity = $entityClass::get()->byID($entityId);

        $logger->info('[PersistContentNode] saving', [
            'entity_class' => $entityClass,
            'entity_id' => $entityId,
            'content_length' => strlen($content),
        ]);

        $entity->saveContent($content);

        return new StopEvent($content);
    }
}
