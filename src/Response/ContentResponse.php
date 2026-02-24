<?php

namespace Hudhaifas\AI\Response;

use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

/**
 * Response DTO from ContentService calls.
 * Contains completion status and optional HITL interrupt data
 * with the generated content for user review.
 */
class ContentResponse {
    public readonly bool $done;
    public readonly ?string $resumeToken;
    public readonly ?string $content;

    public function __construct(
        bool    $done,
        ?string $resumeToken = null,
        ?string $content = null
    ) {
        $this->done = $done;
        $this->resumeToken = $resumeToken;
        $this->content = $content;
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array {
        if ($this->done) {
            return ['done' => true];
        }

        return [
            'resumeToken' => $this->resumeToken,
            'content' => $this->content,
        ];
    }

    /**
     * Whether this response contains a HITL interrupt requiring user action.
     */
    public function hasInterrupt(): bool {
        return !$this->done && $this->resumeToken !== null;
    }

    /**
     * Create a completed response (workflow finished successfully).
     */
    public static function done(): self {
        return new self(done: true);
    }

    /**
     * Create a response from a WorkflowInterrupt (HITL pause).
     */
    public static function fromInterrupt(WorkflowInterrupt $interrupt): self {
        $action = $interrupt->getRequest()->getAction('content');

        return new self(
            done: false,
            resumeToken: $interrupt->getResumeToken(),
            content: $action?->description ?? ''
        );
    }
}
