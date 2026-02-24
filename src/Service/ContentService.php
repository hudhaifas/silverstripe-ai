<?php

namespace Hudhaifas\AI\Service;

use Hudhaifas\AI\Response\ContentResponse;
use Hudhaifas\AI\Util\ContentText;
use Hudhaifas\AI\Workflow\ContentWorkflow;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * ContentService orchestrates AI content generation.
 *
 * HITL FLOW:
 *
 * Phase 1 — generate(): runs the workflow; pauses at ReviewContentNode
 *   with a WorkflowInterrupt containing the generated content.
 *
 * Phase 2 — resume(): user approves/edits/rejects; workflow continues
 *   to PersistContentNode or stops.
 */
class ContentService {
    use Injectable;

    protected LoggerInterface $logger;

    public function __construct() {
        $this->logger = Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Generate content for an entity.
     *
     * Triggers the ContentWorkflow which calls the LLM and pauses
     * for user review. Returns a ContentResponse with either done=true
     * (if skipReview) or a resumeToken + generated content for HITL.
     */
    public function generate(Member $member, DataObject $entity): ContentResponse {
        $preCheck = $member->getModelAndCalculateCost((int)(strlen($entity->getDynamicContext()) / 4), 1000);
        $model = $preCheck['model'];

        $this->logger->info('[ContentService] Generating content', [
            'entity_class' => $entity->ClassName,
            'entity_id' => $entity->ID,
            'member_id' => $member->ID,
            'model' => $model->Name,
        ]);

        try {
            ContentWorkflow::trigger($member, $model, $entity);
            return ContentResponse::done();

        } catch (WorkflowInterrupt $interrupt) {
            $this->logger->info('[ContentService] WorkflowInterrupt (generate)', [
                'resume_token' => $interrupt->getResumeToken(),
            ]);
            return ContentResponse::fromInterrupt($interrupt);
        }
    }

    /**
     * Resume workflow with user decision.
     *
     * @param Member $member The authenticated member
     * @param DataObject $entity The entity being edited
     * @param string $resumeToken Token from the previous interrupt
     * @param string $decision One of: 'approved', 'edit', 'rejected'
     * @param string|null $editedContent User-modified content (for 'edit' decision)
     */
    public function resume(Member $member, DataObject $entity,
                           string $resumeToken, string $decision, ?string $editedContent = null): ContentResponse {
        $preCheck = $member->getModelAndCalculateCost(0, 2000);
        $model = $preCheck['model'];

        $this->logger->info('[ContentService] Resuming workflow', [
            'resume_token' => $resumeToken,
            'decision' => $decision,
            'entity_class' => $entity->ClassName,
            'entity_id' => $entity->ID,
        ]);

        $actionDecision = match ($decision) {
            'approved' => ActionDecision::Approved,
            'edit' => ActionDecision::Edit,
            default => ActionDecision::Rejected,
        };

        $approvalRequest = new ApprovalRequest('User decision', [
            new Action('content', 'Review Content', null, $actionDecision, $editedContent),
        ]);

        try {
            ContentWorkflow::resumeWithDecision($member, $model, $entity, $resumeToken, $approvalRequest);
            return ContentResponse::done();

        } catch (WorkflowInterrupt $interrupt) {
            $this->logger->info('[ContentService] WorkflowInterrupt (resume)', [
                'resume_token' => $interrupt->getResumeToken(),
            ]);
            return ContentResponse::fromInterrupt($interrupt);
        }
    }

    /**
     * Save manually edited content (no AI involved).
     *
     * Used when the user writes content directly without AI generation.
     */
    public function save(DataObject $entity, string $content): void {
        $this->logger->info('[ContentService] Saving content', [
            'entity_class' => $entity->ClassName,
            'entity_id' => $entity->ID,
        ]);

        $entity->saveContent(ContentText::sanitize($content));
    }
}
