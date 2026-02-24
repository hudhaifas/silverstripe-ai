<?php

namespace Hudhaifas\AI\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Fired by AgentRunnerNode after the LLM produces content.
 * The generated text is stored in WorkflowState under STATE_CONTENT.
 */
class ContentGeneratedEvent implements Event {
}
