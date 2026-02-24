<?php

namespace Hudhaifas\AI\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Fired by ReviewContentNode after the user approves or edits the content.
 * The final (possibly edited) text is stored in WorkflowState under STATE_CONTENT.
 */
class ContentApprovedEvent implements Event {
}
