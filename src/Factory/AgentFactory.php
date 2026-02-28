<?php

namespace Hudhaifas\AI\Factory;

use Hudhaifas\AI\Agent\DataObjectAgent;
use Hudhaifas\AI\Model\AIChatModel;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * AgentFactory
 *
 * Creates DataObjectAgent instances based on entity type.
 *
 * CONFIGURATION:
 * Register agent mappings in YAML (no defaults shipped — consuming modules register their own):
 *
 * Hudhaifas\AI\Factory\AgentFactory:
 *   agent_mappings:
 *     Person: 'MyApp\Agent\PersonAgent'
 *     Document: 'MyApp\Agent\DocumentAgent'
 *
 * Mapping resolution walks up the inheritance chain, so a mapping on 'Person'
 * will match Male, Female, and any other Person subclass.
 */
class AgentFactory {
    use Configurable;

    /**
     * Entity class → agent class mappings.
     * Populated entirely via YAML — no hardcoded defaults.
     *
     * @config
     * @var array<string, string>
     */
    private static array $agent_mappings = [];

    /**
     * Create an agent for the given entity.
     *
     * @param Member $member Current member
     * @param AIChatModel $model AI model configuration
     * @param DataObject $entity Context entity (ID=0 means collection mode)
     * @param string|null $resumeToken Token to resume an interrupted workflow
     * @return DataObjectAgent
     */
    public static function createForEntity(
        Member      $member,
        AIChatModel $model,
        DataObject  $entity,
        string      $threadId,
        ?string     $resumeToken = null
    ): DataObjectAgent {
        $agentClass = self::getAgentClassForEntity($entity->ClassName);
        return new $agentClass($member, $model, $entity, $threadId, $resumeToken);
    }

    /**
     * Resolve the agent class for an entity class by walking the inheritance chain.
     *
     * @param string $entityClass
     * @return string Agent FQCN
     * @throws RuntimeException If no mapping is found
     */
    public static function getAgentClassForEntity(string $entityClass): string {
        $mappings = self::config()->get('agent_mappings') ?: [];

        $current = $entityClass;
        do {
            if (isset($mappings[$current])) {
                return $mappings[$current];
            }
            $current = get_parent_class($current);
        } while ($current);

        throw new RuntimeException(
            "No agent mapping found for entity class '{$entityClass}'. " .
            "Register one in YAML: Hudhaifas\\AI\\Factory\\AgentFactory.agent_mappings"
        );
    }
}
