<?php

namespace Hudhaifas\AI\Workflow\Nodes;

use Hudhaifas\AI\Agent\DataObjectAgent;
use Hudhaifas\AI\Model\AIModel;
use Hudhaifas\AI\Model\AIUsageLog;
use Hudhaifas\AI\Workflow\ContentWorkflow;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;

/**
 * AgentRunnerNode
 *
 * Generic workflow node that instantiates any DataObjectAgent subclass,
 * runs a single provider call, stores the result in state, logs usage,
 * and emits the configured output event.
 *
 * Usage in a workflow's nodes() method:
 *
 *   new AgentRunnerNode(
 *       agentClass: ContentAgent::class,
 *       outputEventClass: ContentGeneratedEvent::class,
 *       prompt: 'Generate the content now based on the data provided.',
 *       stateKey: ContentWorkflow::STATE_CONTENT
 *   )
 */
class AgentRunnerNode extends Node {
    /**
     * @param class-string<DataObjectAgent> $agentClass Agent to instantiate
     * @param class-string<Event> $outputEventClass Event to emit on success
     * @param string $prompt User message to send
     * @param string $stateKey State key to store the result
     */
    public function __construct(
        private readonly string $agentClass,
        private readonly string $outputEventClass,
        private readonly string $prompt = 'Generate the content now based on the data provided.',
        private readonly string $stateKey = ContentWorkflow::STATE_CONTENT
    ) {}

    public function __invoke(StartEvent $event, WorkflowState $state): Event {
        $logger = Injector::inst()->get(LoggerInterface::class);

        // Rehydrate context from workflow state
        $member = Member::get()->byID($state->get(ContentWorkflow::STATE_MEMBER_ID));
        $entityClass = $state->get(ContentWorkflow::STATE_ENTITY_CLASS);
        $entity = $entityClass::get()->byID($state->get(ContentWorkflow::STATE_ENTITY_ID));
        $model = AIModel::get()->byID($state->get(ContentWorkflow::STATE_MODEL_ID));

        $agentClassName = $this->agentClass;
        $logger->info('[AgentRunnerNode] running', [
            'agent' => $agentClassName,
            'entity_class' => $entityClass,
            'entity_id' => $entity->ID,
            'model' => $model->Name,
        ]);

        /** @var DataObjectAgent $agent */
        $agent = new $agentClassName($member, $model, $entity);

        $startTime = microtime(true);
        $response = $agent->provider()->chat(new UserMessage($this->prompt));
        $duration = round((microtime(true) - $startTime) * 1000);
        $content = $response->getContent();

        $logger->info('[AgentRunnerNode] completed', [
            'agent' => $agentClassName,
            'duration_ms' => $duration,
            'content_preview' => substr($content, 0, 300),
        ]);

        $state->set($this->stateKey, $content);

        AIUsageLog::record(
            member: $member,
            model: $model,
            contextEntity: $entity,
            usageObj: $response->getUsage(),
            success: true
        );

        return new ($this->outputEventClass)();
    }
}
