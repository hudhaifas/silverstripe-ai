<?php

namespace Hudhaifas\AI\Agent;

use Hudhaifas\AI\Model\AIModel;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * GenerateContentAgent
 *
 * Lightweight agent for single-shot content generation.
 * Gets its instructions from the entity's ContentExtension methods.
 * No tools, no chat history — just a provider call.
 */
class GenerateContentAgent extends DataObjectAgent {
    public function __construct(Member $member, AIModel $model, DataObject $entity) {
        parent::__construct(
            member: $member,
            model: $model,
            contextEntity: $entity,
            threadId: 'content_' . $entity->ClassName . '_' . $entity->ID
        );
    }

    protected function getStaticInstructions(): string {
        return $this->contextEntity->getStaticInstructions();
    }

    protected function getDynamicContext(): string {
        return $this->contextEntity->getDynamicContext();
    }

    public function tools(): array {
        return [];
    }
}
