<?php

namespace Hudhaifas\AI\Service;

use Hudhaifas\AI\Factory\AgentFactory;
use Hudhaifas\AI\Model\AIUsageLog;
use Hudhaifas\AI\Response\AgentResponse;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use Throwable;

/**
 * AgentService orchestrates the AI Agent chatbot.
 *
 * HITL FLOW (NeuronAI WorkflowInterrupt pattern):
 *
 * Phase 1 — chat(): runs the agent; if a write tool is called, WorkflowInterrupt
 *   is thrown and returned to the frontend with a resumeToken.
 *
 * Phase 2 — resume(): frontend sends back resumeToken + user decision;
 *   agent is re-instantiated, tool executes (or is rejected), LLM responds.
 */
class AgentService {
    use Injectable;

    protected LoggerInterface $logger;

    public function __construct() {
        $this->logger = Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Process a new chat message and return the agent's response.
     *
     * @throws HTTPResponse_Exception
     */
    public function chat(
        string     $message,
        Member     $member,
        DataObject $contextEntity,
        string     $threadId
    ): AgentResponse {
        $preCheck = $member->getModelAndCalculateCost((int)(strlen($message) / 4), 1000);
        $selectedModel = $preCheck['model'];

        $this->logger->info('[AgentService] Processing chat', [
            'entity_class' => $contextEntity->ClassName,
            'entity_id' => $contextEntity->ID,
            'member_id' => $member->ID,
            'model' => $selectedModel->Name,
            'provider' => $selectedModel->Provider,
        ]);

        $agent = AgentFactory::createForEntity($member, $selectedModel, $contextEntity, $threadId);

        try {
            $startTime = microtime(true);
            $llmMessage = $agent->chat(new UserMessage($message))->getMessage();
            $duration = round((microtime(true) - $startTime) * 1000);

            $responseContent = $llmMessage->getContent() ?? '';
            $usage = AIUsageLog::record($member, $agent->getModel(), $contextEntity, $llmMessage->getUsage(), true);

            $this->logger->info('[AgentService] Chat completed', ['duration_ms' => $duration]);

            return AgentResponse::fromMessage($responseContent, $usage);

        } catch (WorkflowInterrupt $interrupt) {
            AIUsageLog::record($member, $agent->getModel(), $contextEntity, null, false, 'WorkflowInterrupt');
            $this->logger->info('[AgentService] WorkflowInterrupt (chat)', ['resume_token' => $interrupt->getResumeToken()]);
            return AgentResponse::fromInterrupt($interrupt, $agent);

        } catch (HTTPResponse_Exception $e) {
            AIUsageLog::record($member, $agent->getModel(), $contextEntity, null, false, $e->getResponse()->getBody());
            throw $e;

        } catch (Throwable $e) {
            AIUsageLog::record($member, $agent->getModel(), $contextEntity, null, false, $e->getMessage());
            $this->logger->error('[AgentService] Chat error', ['error' => $e->getMessage()]);
            throw new HTTPResponse_Exception('An unexpected error occurred. Please try again.', 500);
        }
    }

    /**
     * Resume an interrupted workflow after user approval or rejection.
     *
     * @throws HTTPResponse_Exception
     */
    public function resume(
        string     $resumeToken,
        string     $requestPayload,
        bool       $approved,
        Member     $member,
        DataObject $contextEntity,
        string     $threadId
    ): AgentResponse {
        $preCheck = $member->getModelAndCalculateCost(0, 2000);
        $selectedModel = $preCheck['model'];

        $this->logger->info('[AgentService] Resuming workflow', [
            'resume_token' => $resumeToken,
            'approved' => $approved,
            'entity_class' => $contextEntity->ClassName,
            'entity_id' => $contextEntity->ID,
            'model' => $selectedModel->Name,
            'provider' => $selectedModel->Provider,
        ]);

        $requestData = json_decode($requestPayload, true);
        $request = ApprovalRequest::fromArray($requestData);

        foreach ($request->getActions() as $action) {
            if ($approved) {
                $action->approve();
            } else {
                $action->reject('User declined.');
            }
        }

        $agent = AgentFactory::createForEntity($member, $selectedModel, $contextEntity, $threadId, $resumeToken);

        try {
            $startTime = microtime(true);
            $llmMessage = $agent->chat(interrupt: $request)->getMessage();
            $duration = round((microtime(true) - $startTime) * 1000);

            $responseContent = $llmMessage->getContent() ?? '';
            $usage = AIUsageLog::record($member, $agent->getModel(), $contextEntity, $llmMessage->getUsage(), true);

            $this->logger->info('[AgentService] Resume completed', ['duration_ms' => $duration]);

            return AgentResponse::fromMessage($responseContent, $usage);

        } catch (WorkflowInterrupt $interrupt) {
            AIUsageLog::record($member, $agent->getModel(), $contextEntity, null, false, 'WorkflowInterrupt');
            $this->logger->info('[AgentService] WorkflowInterrupt (resume)', ['resume_token' => $interrupt->getResumeToken()]);
            return AgentResponse::fromInterrupt($interrupt, $agent);

        } catch (Throwable $e) {
            AIUsageLog::record($member, $agent->getModel(), $contextEntity, null, false, $e->getMessage());
            $this->logger->error('[AgentService] Resume error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw new HTTPResponse_Exception('An unexpected error occurred. Please try again.', 500);
        }
    }
}
