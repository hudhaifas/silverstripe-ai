<?php

namespace Hudhaifas\AI\Controller;

use Exception;
use Hudhaifas\AI\Exception\CreditLimitExceededException;
use Hudhaifas\AI\Exception\LLMServiceUnavailableException;
use Hudhaifas\AI\Service\ContentService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;

/**
 * ContentController
 *
 * Thin controller for AI content generation endpoints.
 * Routes: /api/ai/content/generate, /api/ai/content/resume, /api/ai/content/save
 */
class ContentController extends BaseAPIController {
    private static $url_segment = 'api/ai/content';
    private static $allowed_actions = ['generate', 'resume', 'save'];
    protected ContentService $contentService;

    protected function init(): void {
        parent::init();
        $this->contentService = Injector::inst()->get(ContentService::class);
    }

    public function generate(HTTPRequest $request): HTTPResponse {
        try {
            $member = $this->requireMember();
            $entity = $this->resolveEntity(
                $request->postVar('class') ?? '',
                (int)$request->postVar('id'),
                $member,
                'edit'
            );

            $response = $this->contentService->generate($member, $entity);
            return $this->json($response->toArray());

        } catch (CreditLimitExceededException $e) {
            return $this->jsonError('insufficient_credits', 402);
        } catch (LLMServiceUnavailableException $e) {
            return $this->jsonError('service_unavailable', 503);
        } catch (Exception $e) {
            $this->logger->error('[ContentController] generate', ['error' => $e->getMessage()]);
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    public function resume(HTTPRequest $request): HTTPResponse {
        try {
            $member = $this->requireMember();
            $entity = $this->resolveEntity(
                $request->postVar('class') ?? '',
                (int)$request->postVar('id'),
                $member,
                'edit'
            );

            $resumeToken = $request->postVar('resumeToken');
            $decision = $request->postVar('decision');

            if (!$resumeToken || !$decision) {
                return $this->jsonError('resumeToken and decision are required', 400);
            }

            $response = $this->contentService->resume(
                $member,
                $entity,
                $resumeToken,
                $decision,
                $request->postVar('content')
            );
            return $this->json($response->toArray());

        } catch (CreditLimitExceededException $e) {
            return $this->jsonError('insufficient_credits', 402);
        } catch (LLMServiceUnavailableException $e) {
            return $this->jsonError('service_unavailable', 503);
        } catch (Exception $e) {
            $this->logger->error('[ContentController] resume', ['error' => $e->getMessage()]);
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    public function save(HTTPRequest $request): HTTPResponse {
        try {
            $member = $this->requireMember();
            $entity = $this->resolveEntity(
                $request->postVar('class') ?? '',
                (int)$request->postVar('id'),
                $member,
                'edit'
            );

            $this->contentService->save($entity, $request->postVar('content') ?? '');
            return $this->json(['done' => true]);

        } catch (Exception $e) {
            $this->logger->error('[ContentController] save', ['error' => $e->getMessage()]);
            return $this->jsonError($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
