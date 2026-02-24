<?php

namespace Hudhaifas\AI\Controller;

use Exception;
use Hudhaifas\AI\Service\AgentService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injector;

/**
 * AgentController
 *
 * HTTP endpoints for the AI agent chat.
 * Routes: POST /api/ai/agent/chat, POST /api/ai/agent/resume
 */
class AgentController extends BaseAPIController {
    private static $url_segment = 'api/ai/agent';
    private static $allowed_actions = ['chat', 'resume'];
    private static $url_handlers = [
        'POST chat' => 'chat',
        'POST resume' => 'resume',
    ];
    /**
     * Whether to call session_write_close() before dispatching to the agent.
     * Enable if using PHP file-based sessions and experiencing blocking.
     *
     * @config
     */
    private static bool $close_session_before_agent = false;
    protected AgentService $agentService;

    protected function init(): void {
        parent::init();
        $this->agentService = Injector::inst()->get(AgentService::class);
    }

    public function chat(HTTPRequest $request): HTTPResponse {
        $startTime = microtime(true);
        $member = null;

        try {
            $payload = $this->parseJsonBody($request);

            if (!isset($payload['message']) || trim($payload['message']) === '') {
                throw new HTTPResponse_Exception('Missing required field: message', 400);
            }

            $entityClass = $payload['context']['entity_class'] ?? null;
            $entityId = (int)($payload['context']['entity_id'] ?? 0);
            $threadId = $payload['thread_id'] ?? null;

            if (!$entityClass) {
                throw new HTTPResponse_Exception('Missing required field: context.entity_class', 400);
            }
            if (!$threadId) {
                throw new HTTPResponse_Exception('Missing required field: thread_id', 400);
            }

            $member = $this->requireMember();
            $entity = $this->resolveEntity($entityClass, $entityId, $member);

            if ($this->config()->get('close_session_before_agent')) {
                session_write_close();
            }

            $response = $this->agentService->chat(
                trim($payload['message']), $member, $entity, $threadId
            );

            $this->logger->info('[AgentController] chat done', [
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return $this->json($response->toArray());

        } catch (HTTPResponse_Exception $e) {
            $code = $e->getResponse()->getStatusCode();
            $this->logger->log($code >= 500 ? 'error' : 'warning', '[AgentController] chat error', [
                'error' => $e->getResponse()->getBody(),
            ]);
            return $this->jsonError($e->getResponse()->getBody(), $code);
        } catch (Exception $e) {
            $this->logger->error('[AgentController] chat unexpected', [
                'member_id' => $member?->ID,
                'error' => $e->getMessage(),
            ]);
            return $this->jsonError('An unexpected error occurred. Please try again.', 500);
        }
    }

    public function resume(HTTPRequest $request): HTTPResponse {
        $startTime = microtime(true);
        $member = null;

        try {
            $payload = $this->parseJsonBody($request);

            if (!isset($payload['resume_token'], $payload['request_payload'])) {
                throw new HTTPResponse_Exception('Missing required fields: resume_token, request_payload', 400);
            }

            $entityClass = $payload['context']['entity_class'] ?? null;
            $entityId = (int)($payload['context']['entity_id'] ?? 0);
            $threadId = $payload['thread_id'] ?? null;

            if (!$entityClass) {
                throw new HTTPResponse_Exception('Missing required field: context.entity_class', 400);
            }
            if (!$threadId) {
                throw new HTTPResponse_Exception('Missing required field: thread_id', 400);
            }

            $member = $this->requireMember();
            $entity = $this->resolveEntity($entityClass, $entityId, $member);

            if ($this->config()->get('close_session_before_agent')) {
                session_write_close();
            }

            $response = $this->agentService->resume(
                resumeToken: $payload['resume_token'],
                requestPayload: $payload['request_payload'],
                approved: (bool)($payload['approved'] ?? false),
                member: $member,
                contextEntity: $entity,
                threadId: $threadId
            );

            $this->logger->info('[AgentController] resume done', [
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return $this->json($response->toArray());

        } catch (HTTPResponse_Exception $e) {
            $this->logger->warning('[AgentController] resume error', [
                'error' => $e->getResponse()->getBody(),
            ]);
            return $this->jsonError($e->getResponse()->getBody(), $e->getResponse()->getStatusCode());
        } catch (Exception $e) {
            $this->logger->error('[AgentController] resume unexpected', [
                'member_id' => $member?->ID,
                'error' => $e->getMessage(),
            ]);
            return $this->jsonError('An unexpected error occurred. Please try again.', 500);
        }
    }
}
