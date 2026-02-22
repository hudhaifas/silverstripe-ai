<?php

namespace Hudhaifas\AI\Controller;

use Exception;
use Hudhaifas\AI\Service\AgentService;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * AgentController handles HTTP requests for the AI agent.
 *
 * ENDPOINTS:
 *
 * POST /api/agent/chat
 *   Regular chat message. If a write tool is triggered, returns an interrupt
 *   with resumeToken + requestPayload for the frontend to display and resume.
 *
 * POST /api/agent/resume
 *   Resume an interrupted workflow after user approval or rejection.
 */
class AgentController extends Controller {
    private static $url_segment = 'agent';
    private static $allowed_actions = ['chat', 'resume'];
    private static $url_handlers = [
        'POST chat' => 'chat',
        'POST resume' => 'resume',
    ];
    /**
     * Whether to call session_write_close() before dispatching to the agent.
     *
     * Disabled by default. Enable this if your app uses PHP file-based sessions
     * and you experience blocking on concurrent requests (e.g. long-running agent
     * calls blocking other tabs). Not needed when using database or Redis sessions.
     *
     * YAML override:
     *   Hudhaifas\AI\Controller\AgentController:
     *     close_session_before_agent: true
     *
     * @config
     * @var bool
     */
    private static bool $close_session_before_agent = false;

    public function chat(HTTPRequest $request): HTTPResponse {
        $startTime = microtime(true);
        $logger = Injector::inst()->get(LoggerInterface::class);
        $member = null;

        try {
            $payload = $this->parseRequest($request, $logger);

            if (!isset($payload['message']) || trim($payload['message']) === '') {
                throw new HTTPResponse_Exception('Missing required field: message', 400);
            }

            $message = trim($payload['message']);
            $entityClass = $payload['context']['entity_class'] ?? null;
            $entityId = (int)($payload['context']['entity_id'] ?? 0);
            $threadId = $payload['thread_id'] ?? null;

            $logger->info('[AgentController] chat', [
                'entity_class' => $entityClass,
                'entity_id' => $entityId,
                'ip' => $request->getIP(),
            ]);

            $member = $this->requireMember();

            if (!$entityClass) {
                throw new HTTPResponse_Exception('Missing required field: context.entity_class', 400);
            }

            if ($entityId < 0) {
                throw new HTTPResponse_Exception('entity_id must be a positive integer', 400);
            }

            if (!$threadId) {
                throw new HTTPResponse_Exception('Missing required field: thread_id', 400);
            }

            $entity = $this->resolveEntity($entityClass, $entityId, $member);

            if ($this->config()->get('close_session_before_agent')) {
                session_write_close();
            }

            $response = AgentService::create()->chat($message, $member, $entity, $threadId);

            $logger->info('[AgentController] chat done', [
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return $this->jsonSuccess($response->toArray());

        } catch (HTTPResponse_Exception $e) {
            $res = $e->getResponse();
            $code = $res->getStatusCode();
            $logger->log($code >= 500 ? 'error' : 'warning', '[AgentController] chat error', [
                'error' => $res->getBody(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
            return $this->jsonError($res->getBody(), $code);
        } catch (Exception $e) {
            $logger->error('[AgentController] chat unexpected', [
                'member_id' => $member?->ID,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
            return $this->jsonError('An unexpected error occurred. Please try again.', 500);
        }
    }

    public function resume(HTTPRequest $request): HTTPResponse {
        $startTime = microtime(true);
        $logger = Injector::inst()->get(LoggerInterface::class);
        $member = null;

        try {
            $payload = $this->parseRequest($request, $logger);

            if (!isset($payload['resume_token'], $payload['request_payload'])) {
                throw new HTTPResponse_Exception('Missing required fields: resume_token, request_payload', 400);
            }

            $entityClass = $payload['context']['entity_class'] ?? null;
            $entityId = (int)($payload['context']['entity_id'] ?? 0);
            $threadId = $payload['thread_id'] ?? null;

            $member = $this->requireMember();

            if (!$entityClass) {
                throw new HTTPResponse_Exception('Missing required field: context.entity_class', 400);
            }

            if (!$threadId) {
                throw new HTTPResponse_Exception('Missing required field: thread_id', 400);
            }

            $entity = $this->resolveEntity($entityClass, $entityId, $member);

            if ($this->config()->get('close_session_before_agent')) {
                session_write_close();
            }

            $response = AgentService::create()->resume(
                resumeToken: $payload['resume_token'],
                requestPayload: $payload['request_payload'],
                approved: (bool)($payload['approved'] ?? false),
                member: $member,
                contextEntity: $entity,
                threadId: $threadId
            );

            $logger->info('[AgentController] resume done', [
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return $this->jsonSuccess($response->toArray());

        } catch (HTTPResponse_Exception $e) {
            $res = $e->getResponse();
            $logger->warning('[AgentController] resume error', [
                'error' => $res->getBody(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
            return $this->jsonError($res->getBody(), $res->getStatusCode());
        } catch (Exception $e) {
            $logger->error('[AgentController] resume unexpected', [
                'member_id' => $member?->ID,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
            return $this->jsonError('An unexpected error occurred. Please try again.', 500);
        }
    }

    /**
     * Parse and validate the JSON request body.
     */
    private function parseRequest(HTTPRequest $request, LoggerInterface $logger): array {
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $logger->warning('[AgentController] Invalid Content-Type', ['ip' => $request->getIP()]);
            throw new HTTPResponse_Exception('Content-Type must be application/json', 400);
        }

        $rawBody = $request->getBody() ?: file_get_contents('php://input');
        if (empty($rawBody)) {
            $logger->warning('[AgentController] Empty request body', ['ip' => $request->getIP()]);
            throw new HTTPResponse_Exception('Request body is empty', 400);
        }

        $payload = json_decode($rawBody, true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            $logger->warning('[AgentController] Invalid JSON', ['error' => json_last_error_msg()]);
            throw new HTTPResponse_Exception('Invalid JSON: ' . json_last_error_msg(), 400);
        }

        return $payload;
    }

    /**
     * Require an authenticated member or throw 401.
     */
    private function requireMember(): Member {
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception('Authentication required', 401);
        }
        return $member;
    }

    /**
     * Resolve a DataObject by class/id and verify view permission.
     *
     * entity_class is always required so AgentFactory can resolve the correct agent.
     * entity_id is optional — omitting it means collection mode (agent works across all records of that type),
     * in which case a new unsaved instance is returned with ID 0.
     */
    private function resolveEntity(string $class, int $id, Member $member): DataObject {
        if (!ClassInfo::exists($class) || !is_a($class, DataObject::class, true)) {
            throw new HTTPResponse_Exception('Invalid entity class', 400);
        }

        if (!$id) {
            return $class::create(); // collection mode — unsaved, ID = 0
        }

        $entity = $class::get()->byID($id);
        if (!$entity) {
            throw new HTTPResponse_Exception('Entity not found', 404);
        }

        if (!$entity->canView($member)) {
            throw new HTTPResponse_Exception('You do not have permission to view this resource', 403);
        }

        return $entity;
    }

    private function jsonSuccess(array $data): HTTPResponse {
        return HTTPResponse::create(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        )->addHeader('Content-Type', 'application/json')->setStatusCode(200);
    }

    private function jsonError(string $message, int $statusCode): HTTPResponse {
        return HTTPResponse::create(
            json_encode(['success' => false, 'message' => $message, 'blocks' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        )->addHeader('Content-Type', 'application/json')->setStatusCode($statusCode);
    }
}
