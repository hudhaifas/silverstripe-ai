<?php

namespace Hudhaifas\AI\Response;

use Hudhaifas\AI\Agent\DataObjectAgent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

/**
 * Response DTO from an agent service call.
 *
 * Contains the raw response message, parsed blocks for rendering,
 * token usage statistics, and optional HITL interrupt data.
 */
class AgentResponse {
    /**
     * @var string Raw response message from the LLM
     */
    public readonly string $message;
    /**
     * @var array Parsed message blocks (text and entity references)
     */
    public readonly array $blocks;
    /**
     * @var array Token usage statistics from the API call
     */
    public readonly array $usage;
    /**
     * @var array|null HITL interrupt data (resumeToken, actions, requestPayload)
     */
    public readonly ?array $interrupt;

    /**
     * @param string $message Raw response message
     * @param array $blocks Parsed message blocks
     * @param array $usage Token usage statistics
     * @param array|null $interrupt Optional HITL interrupt data
     */
    public function __construct(
        string $message,
        array  $blocks,
        array  $usage = [],
        ?array $interrupt = null
    ) {
        $this->message = $message;
        $this->blocks = $blocks;
        $this->usage = $usage;
        $this->interrupt = $interrupt;
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array
     */
    public function toArray(): array {
        $data = [
            'success' => true,
            'message' => $this->message,
            'blocks' => $this->blocks,
            'usage' => $this->usage,
        ];

        if ($this->interrupt !== null) {
            $data['interrupt'] = $this->interrupt;
        }

        return $data;
    }

    /**
     * Whether this response contains a HITL interrupt.
     */
    public function hasInterrupt(): bool {
        return $this->interrupt !== null;
    }

    /**
     * Build an AgentResponse from a completed LLM message.
     * Parses [Display Name](ClassName:id) references into entity_reference blocks.
     */
    public static function fromMessage(string $content, array $usage): self {
        $blocks = [];
        $pattern = '/\[([^\]]+)\]\(([A-Za-z][A-Za-z0-9_]*):(\d+)\)/';
        $lastIndex = 0;
        $offset = 0;

        while (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $fullMatch = $matches[0][0];
            $matchStart = $matches[0][1];

            if ($matchStart > $lastIndex) {
                $textBefore = substr($content, $lastIndex, $matchStart - $lastIndex);
                if (trim($textBefore) !== '') {
                    $blocks[] = ['type' => 'text', 'content' => $textBefore];
                }
            }

            $entityClass = $matches[2][0];
            $entityId = (int)$matches[3][0];
            $link = null;
            if (class_exists($entityClass)) {
                $entity = $entityClass::get()->byID($entityId);
                if ($entity && method_exists($entity, 'getObjectLink')) {
                    $link = $entity->getObjectLink() ?: null;
                }
            }

            $blocks[] = [
                'type' => 'entity_reference',
                'entity_class' => $entityClass,
                'entity_id' => $entityId,
                'display_name' => $matches[1][0],
                'link' => $link,
            ];

            $lastIndex = $matchStart + strlen($fullMatch);
            $offset = $lastIndex;
        }

        if ($lastIndex < strlen($content)) {
            $remaining = substr($content, $lastIndex);
            if (trim($remaining) !== '') {
                $blocks[] = ['type' => 'text', 'content' => $remaining];
            }
        }

        return new self(
            message: $content,
            blocks: $blocks ?: [['type' => 'text', 'content' => $content]],
            usage: $usage
        );
    }

    /**
     * Build an AgentResponse for a WorkflowInterrupt (HITL approval card).
     * Delegates action summary generation to the agent so AgentResponse stays domain-agnostic.
     */
    public static function fromInterrupt(WorkflowInterrupt $interrupt, DataObjectAgent $agent): self {
        $resumeToken = $interrupt->getResumeToken();
        $approvalRequest = $interrupt->getRequest();

        $actions = [];
        foreach ($approvalRequest->getActions() as $action) {
            $inputs = [];
            if ($action->description && preg_match('/Inputs:\s*(\{.*\}|\[.*\])/s', $action->description, $m)) {
                $inputs = json_decode($m[1], true) ?: [];
            }
            $actions[] = [
                'name' => $action->name,
                'description' => $agent->summariseAction($action->name, $inputs),
            ];
        }

        $actionCount = count($actions);
        $message = $actionCount === 1 ? 'Confirm action' : "Confirm {$actionCount} actions";

        return new self(
            message: $message,
            blocks: [['type' => 'text', 'content' => $message]],
            usage: [],
            interrupt: [
                'resumeToken' => $resumeToken,
                'message' => $message,
                'actions' => $actions,
                'requestPayload' => json_encode($approvalRequest),
            ]
        );
    }
}
