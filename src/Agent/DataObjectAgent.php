<?php

namespace Hudhaifas\AI\Agent;

use Hudhaifas\AI\Model\AIModel;
use Hudhaifas\AI\Provider\CachedAnthropic;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * DataObjectAgent
 *
 * Abstract base class for NeuronAI agents that operate on SilverStripe DataObject entities.
 *
 * ARCHITECTURE:
 *
 * 1. Entity-agnostic — works with any DataObject type; subclasses provide domain logic
 *    via getStaticInstructions(), getDynamicContext(), and tools().
 *
 * 2. Two context modes:
 *    - Individual mode ($contextEntity set): agent knows which record the user is viewing.
 *    - Collection mode ($contextEntity null): generic assistant, requires explicit IDs.
 *
 * 3. Provider selection driven by AIModel->Provider field (OpenAI / Anthropic).
 *    Anthropic uses CachedAnthropic for prompt caching (up to 90% cost reduction).
 *
 * 4. Conversation history stored via SilverStripeCacheHistory by default (1-hour TTL).
 *    HITL workflow state stored via SilverStripeCachePersistence by default (1-hour TTL).
 *    Both can be swapped to Redis backends via YAML — see createChatHistory() / createPersistence().
 *
 * 5. NeuronAI LogObserver attached when AI_VERBOSE_LOGGING=true.
 *
 * SUBCLASS RESPONSIBILITIES:
 * - Implement getStaticInstructions(): cacheable part of the system prompt
 * - Implement getDynamicContext(): ephemeral part (entity context, current date, etc.)
 * - Implement tools(): return the tool instances for this agent
 * - Optionally override createChatHistory(): swap the conversation history backend
 * - Optionally override createPersistence(): swap the HITL workflow persistence backend
 * - Optionally implement summariseAction() for HITL interrupt card summaries
 *
 * @package Hudhaifas\AI\Agent
 */
abstract class DataObjectAgent extends Agent {
    /**
     * The member using the agent.
     *
     * @var Member
     */
    protected Member $member;
    /**
     * The AI model configuration record.
     *
     * @var AIModel
     */
    protected AIModel $model;
    /**
     * The entity context for this session (null = collection mode).
     *
     * @var DataObject
     */
    protected DataObject $contextEntity;
    /**
     * @var string
     */
    protected string $threadId;

    /**
     * Build a human-readable summary for a pending tool action.
     *
     * Called by the service layer during a WorkflowInterrupt so the HITL card
     * can show a meaningful description. Delegates to the matching tool's static
     * summarise() method — the service layer never needs to know tool FQCNs.
     *
     * Override in subclasses if you need custom dispatch logic.
     *
     * @param string $toolName Tool name from the ApprovalRequest action.
     * @param array $inputs Parsed tool inputs.
     * @return string Human-readable one-line summary.
     */
    public function summariseAction(string $toolName, array $inputs): string {
        foreach ($this->tools() as $tool) {
            if ($tool->getName() === $toolName && method_exists($tool, 'summarise')) {
                return $tool::summarise($inputs);
            }
        }
        return "Execute: {$toolName}";
    }

    /**
     * Return the static (cacheable) part of the system prompt.
     *
     * This should contain general rules, tool usage instructions, and response
     * formatting guidelines — content that rarely changes between requests.
     * Claude caches this for 5 minutes, reducing costs by up to 90%.
     *
     * @return string
     */
    abstract protected function getStaticInstructions(): string;

    /**
     * Return the dynamic (ephemeral) part of the system prompt.
     *
     * This should contain the current entity context, date/time, and any
     * session-specific information. Not cached.
     *
     * @return string
     */
    abstract protected function getDynamicContext(): string;

    /**
     * Combine static instructions and dynamic context into the full system prompt.
     *
     * Do not override this — implement getStaticInstructions() and getDynamicContext().
     *
     * @return string
     */
    public function instructions(): string {
        return $this->getStaticInstructions() . "\n\n" . $this->getDynamicContext();
    }

    /**
     * @param Member $member The member using the agent.
     * @param AIModel $model The AI model configuration.
     * @param DataObject $contextEntity The entity context (ID=0 = collection mode).
     * @param string $threadId Session thread ID generated by the frontend.
     * @param string|null $resumeToken Token to resume an interrupted workflow.
     * @throws WorkflowException|NotFoundExceptionInterface
     */
    public function __construct(
        Member     $member,
        AIModel    $model,
        DataObject $contextEntity,
        string     $threadId,
        ?string    $resumeToken = null
    ) {
        $this->member = $member;
        $this->model = $model;
        $this->contextEntity = $contextEntity;
        $this->threadId = $threadId;

        parent::__construct(
            persistence: $this->createPersistence(),
            resumeToken: $resumeToken
        );

        if ($this->isVerboseLoggingEnabled()) {
            $logger = Injector::inst()->get(LoggerInterface::class);
            $this->observe(new LogObserver($logger));
        }
    }

    /**
     * @return bool
     */
    protected function isVerboseLoggingEnabled(): bool {
        $value = Environment::getEnv('AI_VERBOSE_LOGGING');
        return $value !== 'false' && $value !== '0';
    }

    /**
     * @return bool
     */
    public function isCollectionMode(): bool {
        return !$this->contextEntity->isInDB();
    }

    public function isIndividualMode(): bool {
        return $this->contextEntity->isInDB();
    }

    public function getContextEntity(): DataObject {
        return $this->contextEntity;
    }

    /**
     * @return Member
     */
    public function getMember(): Member {
        return $this->member;
    }

    /**
     * @return string
     */
    public function getModelName(): string {
        return $this->model->Name;
    }

    /**
     * @return AIModel
     */
    public function getModel(): AIModel {
        return $this->model;
    }

    /**
     * Calculate the token budget for conversation history.
     *
     * Allocates 50% of the model's context window to history, bounded between
     * 10K and 50K tokens to balance context preservation against cost/latency.
     *
     * @return int
     */
    protected function getContextWindow(): int {
        $modelContextWindow = $this->model->ContextWindow;

        if (!$modelContextWindow || $modelContextWindow <= 0) {
            $modelContextWindow = 128000;
        }

        $historyWindow = (int)($modelContextWindow * 0.5);
        return max(10000, min(50000, $historyWindow));
    }

    /**
     * Get or create chat history for this thread.
     *
     * Delegates to createChatHistory() — override that method in subclasses
     * to swap the storage backend without touching this method.
     */
    protected function chatHistory(): ChatHistoryInterface {
        return $this->createChatHistory(
            threadId: "{$this->threadId}_{$this->contextEntity->ID}",
            contextWindow: $this->getContextWindow()
        );
    }

    /**
     * Create the chat history instance for this thread.
     *
     * Default: SilverStripe-cache-backed via Injector-resolved ChatHistoryInterface.
     * Override in subclasses or rebind ChatHistoryInterface in YAML to swap backend.
     *
     * Example YAML override (Redis):
     *   SilverStripe\Core\Injector\Injector:
     *     NeuronAI\Chat\History\ChatHistoryInterface:
     *       class: Hudhaifas\AI\Chat\RedisChatHistory
     */
    protected function createChatHistory(string $threadId, int $contextWindow): ChatHistoryInterface {
        return Injector::inst()->createWithArgs(ChatHistoryInterface::class, [$threadId, $contextWindow]);
    }

    /**
     * Create the workflow persistence instance for HITL state.
     *
     * Default: SilverStripe-cache-backed via Injector-resolved PersistenceInterface.
     * Override in subclasses to use a different persistence backend.
     *
     * Example:
     *   protected function createPersistence(): PersistenceInterface {
     *       return new DatabasePersistence();
     *   }
     */
    protected function createPersistence(): PersistenceInterface {
        return Injector::inst()->get(PersistenceInterface::class);
    }

    /**
     * Instantiate the AI provider based on AIModel->Provider.
     *
     * Anthropic uses CachedAnthropic with split prompt parts for caching.
     * OpenAI uses the standard NeuronAI OpenAI provider with a 120s timeout.
     *
     * Override in subclasses to add support for additional providers.
     *
     * @return AIProviderInterface
     * @throws RuntimeException If provider is unsupported or API key is missing.
     */
    public function provider(): AIProviderInterface {
        $providerName = $this->model->Provider;
        $modelName = $this->model->Name;

        switch ($providerName) {
            case 'OpenAI':
                $apiKey = Environment::getEnv('OPENAI_API_KEY');
                if (!$apiKey) {
                    throw new RuntimeException('OPENAI_API_KEY environment variable is not set');
                }
                return new OpenAI(
                    key: $apiKey,
                    model: $modelName,
                    parameters: [],
                    httpClient: (new GuzzleHttpClient())->withTimeout(120.0)
                );

            case 'Anthropic':
                $apiKey = Environment::getEnv('ANTHROPIC_API_KEY');
                if (!$apiKey) {
                    throw new RuntimeException('ANTHROPIC_API_KEY environment variable is not set');
                }
                return (new CachedAnthropic(
                    key: $apiKey,
                    model: $modelName,
                    parameters: [],
                    httpClient: (new GuzzleHttpClient())->withTimeout(120.0)
                ))
                    ->withPromptCaching()
                    ->systemPromptBlocks([
                        [
                            'type' => 'text',
                            'text' => $this->getStaticInstructions(),
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->getDynamicContext(),
                        ],
                    ]);

            default:
                throw new RuntimeException("Unsupported AI provider: {$providerName}");
        }
    }
}
