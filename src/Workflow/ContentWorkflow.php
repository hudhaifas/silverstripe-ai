<?php

namespace Hudhaifas\AI\Workflow;

use Hudhaifas\AI\Agent\GenerateContentAgent;
use Hudhaifas\AI\Model\AIModel;
use Hudhaifas\AI\Workflow\Events\ContentGeneratedEvent;
use Hudhaifas\AI\Workflow\Nodes\AgentRunnerNode;
use Hudhaifas\AI\Workflow\Nodes\PersistContentNode;
use Hudhaifas\AI\Workflow\Nodes\ReviewContentNode;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * ContentWorkflow
 *
 * HITL workflow for AI content generation.
 *
 * Flow:
 *   1. AgentRunnerNode (GenerateContentAgent) — calls provider, stores text
 *   2. ReviewContentNode                      — HITL interrupt for user review
 *   3. PersistContentNode                     — saves approved/edited content
 *
 * Only serializable primitives in WorkflowState — nodes rehydrate
 * DataObjects from IDs when needed.
 */
class ContentWorkflow extends Workflow {
    const STATE_MEMBER_ID = '__member_id';
    const STATE_ENTITY_CLASS = '__entity_class';
    const STATE_ENTITY_ID = '__entity_id';
    const STATE_MODEL_ID = '__model_id';
    const STATE_CONTENT = 'content';
    const STATE_SKIP_REVIEW = '__skip_review';

    public function __construct(
        Member     $member,
        AIModel    $model,
        DataObject $entity,
        ?string    $resumeToken = null
    ) {
        $logger = Injector::inst()->get(LoggerInterface::class);
        $logger->info('[ContentWorkflow] __construct', [
            'member_id' => $member->ID,
            'entity_class' => $entity->ClassName,
            'entity_id' => $entity->ID,
            'model_name' => $model->Name,
            'provider' => $model->Provider,
            'resume_token' => $resumeToken,
        ]);

        $state = new WorkflowState();
        $state->set(self::STATE_MEMBER_ID, $member->ID);
        $state->set(self::STATE_ENTITY_CLASS, $entity->ClassName);
        $state->set(self::STATE_ENTITY_ID, $entity->ID);
        $state->set(self::STATE_MODEL_ID, $model->ID);

        parent::__construct(
            persistence: Injector::inst()->get(PersistenceInterface::class),
            resumeToken: $resumeToken,
            state: $state
        );

        if ($this->isVerboseLoggingEnabled()) {
            $this->observe(new LogObserver($logger));
        }
    }

    protected function isVerboseLoggingEnabled(): bool {
        $value = Environment::getEnv('AI_VERBOSE_LOGGING');
        return $value !== 'false' && $value !== '0';
    }

    protected function nodes(): array {
        return [
            new AgentRunnerNode(
                agentClass: GenerateContentAgent::class,
                outputEventClass: ContentGeneratedEvent::class,
                stateKey: self::STATE_CONTENT
            ),
            new ReviewContentNode(),
            new PersistContentNode(),
        ];
    }

    // ── Static factories ─────────────────────────────────────────────────────

    /**
     * @throws WorkflowException
     */
    public static function trigger(Member $member, AIModel $model, DataObject $entity,
                                   bool   $skipReview = false): self {
        $workflow = new self($member, $model, $entity);
        $workflow->state->set(self::STATE_SKIP_REVIEW, $skipReview);
        $workflow->init()->run();

        return $workflow;
    }

    /**
     * @throws WorkflowException
     */
    public static function resumeWithDecision(Member $member, AIModel $model, DataObject $entity,
                                              string $resumeToken, ApprovalRequest $approvalRequest): self {
        $workflow = new self($member, $model, $entity, $resumeToken);
        $workflow->init($approvalRequest)->run();

        return $workflow;
    }
}
