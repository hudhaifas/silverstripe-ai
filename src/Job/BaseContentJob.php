<?php

namespace Hudhaifas\AI\Job;

use Exception;
use Hudhaifas\AI\Workflow\ContentWorkflow;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Security\DefaultAdminService;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * BaseContentJob
 *
 * Batch-generates AI content for all entities of a given type that are missing it.
 * Concrete subclasses provide entity class, field name, and title accessor.
 */
abstract class BaseContentJob extends AbstractQueuedJob {
    use Configurable;

    protected $entityIDs = [];
    protected $chunkSize = 5;
    private static $admin_email = null;

    abstract protected function getEntityClass(): string;

    abstract protected function getContentField(): string;

    abstract protected function getEntityTitle($entity): string;

    public function getRunAsMemberID(): ?int {
        $email = $this->config()->admin_email;
        if (!$email) {
            return null;
        }
        $service = DefaultAdminService::singleton();
        $member = $service->findOrCreateAdmin($email);
        return $member ? (int)$member->ID : null;
    }

    public function getJobType() {
        return QueuedJob::LARGE;
    }

    public function setup() {
        $class = $this->getEntityClass();
        $field = $this->getContentField();

        $this->entityIDs = $class::get()->filter([$field => ['', null]])->column('ID');
        $this->totalSteps = count($this->entityIDs);
    }

    public function process() {
        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
            return;
        }

        $class = $this->getEntityClass();
        $field = $this->getContentField();
        $member = Member::get()->byID($this->getRunAsMemberID());

        if (!$member) {
            $this->addMessage('Missing member — aborting.');
            $this->isComplete = true;
            return;
        }

        try {
            $preCheck = $member->getModelAndCalculateCost(2000, 1000);
            $model = $preCheck['model'];
        } catch (Exception $e) {
            $this->addMessage('Model resolution failed — aborting: ' . $e->getMessage());
            $this->isComplete = true;
            return;
        }

        $processed = 0;

        for ($i = 0; $i < $this->chunkSize && $this->currentStep < $this->totalSteps; $i++) {
            if (!isset($this->entityIDs[$this->currentStep])) {
                $this->currentStep++;
                continue;
            }

            $entityID = $this->entityIDs[$this->currentStep];
            $entity = $class::get()->byID($entityID);

            if ($entity && !$entity->$field) {
                try {
                    ContentWorkflow::trigger($member, $model, $entity, skipReview: true);
                    $processed++;
                    $this->addMessage("Generated: {$entity->ID} — {$this->getEntityTitle($entity)}");
                } catch (Exception $e) {
                    $this->addMessage("Failed {$entity->ID} — {$this->getEntityTitle($entity)}: {$e->getMessage()}");
                }
            }

            $this->currentStep++;
        }

        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
            $this->addMessage("Completed all {$this->totalSteps} items.");
        } else {
            $this->addMessage("Processed {$processed} items ({$this->currentStep}/{$this->totalSteps}).");
        }
    }
}
