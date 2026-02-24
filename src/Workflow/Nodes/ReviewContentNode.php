<?php

namespace Hudhaifas\AI\Workflow\Nodes;

use Hudhaifas\AI\Workflow\ContentWorkflow;
use Hudhaifas\AI\Workflow\Events\ContentApprovedEvent;
use Hudhaifas\AI\Workflow\Events\ContentGeneratedEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Handles ContentGeneratedEvent: interrupts for user review.
 *
 * On resume:
 *   - Approved → ContentApprovedEvent (content from state, unchanged)
 *   - Edit     → stores $action->feedback as new content, ContentApprovedEvent
 *   - Rejected → StopEvent (no persist)
 */
class ReviewContentNode extends Node {
    public function __invoke(ContentGeneratedEvent $event, WorkflowState $state): Event {
        $logger = Injector::inst()->get(LoggerInterface::class);
        $content = $state->get(ContentWorkflow::STATE_CONTENT, '');

        $logger->info('[ReviewContentNode] invoked', [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 300),
        ]);

        // Batch path — skip HITL interrupt when flag is set
        if ($state->get(ContentWorkflow::STATE_SKIP_REVIEW, false)) {
            $logger->info('[ReviewContentNode] skip_review flag set — bypassing interrupt');
            return new ContentApprovedEvent();
        }

        $logger->info('[ReviewContentNode] issuing interrupt (HITL)');

        $resumeRequest = $this->interrupt(
            new ApprovalRequest(
                'Review the generated content before saving.',
                [new Action('content', 'Review Content', $content)]
            )
        );

        // ── Resumed ──────────────────────────────────────────────────────────
        $action = $resumeRequest->getAction('content');

        $logger->info('[ReviewContentNode] resumed', [
            'action_found' => $action !== null,
            'decision' => $action?->decision?->name ?? 'null',
            'feedback_len' => strlen($action?->feedback ?? ''),
        ]);

        if ($action === null || $action->isRejected()) {
            $logger->info('[ReviewContentNode] rejected — returning StopEvent');
            return new StopEvent();
        }

        if ($action->decision === ActionDecision::Edit && $action->feedback !== null) {
            $logger->info('[ReviewContentNode] user edited content', [
                'new_length' => strlen($action->feedback),
                'new_preview' => substr($action->feedback, 0, 300),
            ]);
            $state->set(ContentWorkflow::STATE_CONTENT, $action->feedback);
        }

        $logger->info('[ReviewContentNode] approved — returning ContentApprovedEvent');
        return new ContentApprovedEvent();
    }
}
