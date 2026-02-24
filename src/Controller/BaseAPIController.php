<?php

namespace Hudhaifas\AI\Controller;

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
 * BaseAPIController
 *
 * Shared infrastructure for JSON API controllers.
 * Provides auth, entity resolution, and JSON responses.
 */
abstract class BaseAPIController extends Controller {
    protected LoggerInterface $logger;

    protected function init(): void {
        parent::init();
        $this->logger = Injector::inst()->get(LoggerInterface::class);
        $this->getResponse()->addHeader('Content-Type', 'application/json');
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    /**
     * @throws HTTPResponse_Exception
     */
    protected function requireMember(): Member {
        $member = Security::getCurrentUser();
        if (!$member) {
            throw new HTTPResponse_Exception('Authentication required', 401);
        }
        return $member;
    }

    // ── Entity resolution ────────────────────────────────────────────────────

    /**
     * Resolve a DataObject by class name and ID.
     * Returns an unsaved instance (ID=0) when $id is 0 (collection mode).
     *
     * @throws HTTPResponse_Exception
     */
    protected function resolveEntity(string $class, int $id, Member $member, string $permission = 'view'): DataObject {
        if (!ClassInfo::exists($class) || !is_a($class, DataObject::class, true)) {
            throw new HTTPResponse_Exception('Invalid entity class', 400);
        }

        if (!$id) {
            return $class::create();
        }

        $entity = $class::get()->byID($id);
        if (!$entity) {
            throw new HTTPResponse_Exception('Entity not found', 404);
        }

        $check = $permission === 'edit' ? $entity->canEdit($member) : $entity->canView($member);
        if (!$check) {
            throw new HTTPResponse_Exception('Access denied', 403);
        }

        return $entity;
    }

    // ── JSON responses ───────────────────────────────────────────────────────

    protected function json(array $data, int $statusCode = 200): HTTPResponse {
        return HTTPResponse::create(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $statusCode
        )->addHeader('Content-Type', 'application/json');
    }

    protected function jsonError(string $message, int $statusCode = 500): HTTPResponse {
        return $this->json(['error' => $message], $statusCode);
    }

    // ── Request parsing ──────────────────────────────────────────────────────

    /**
     * Parse a JSON request body.
     *
     * @throws HTTPResponse_Exception
     */
    protected function parseJsonBody(HTTPRequest $request): array {
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') === false) {
            throw new HTTPResponse_Exception('Content-Type must be application/json', 400);
        }

        $raw = $request->getBody() ?: file_get_contents('php://input');
        if (empty($raw)) {
            throw new HTTPResponse_Exception('Request body is empty', 400);
        }

        $payload = json_decode($raw, true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new HTTPResponse_Exception('Invalid JSON: ' . json_last_error_msg(), 400);
        }

        return $payload;
    }
}
