<?php

namespace Hudhaifas\AI\Model;

use NeuronAI\Chat\Messages\Usage;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * AIUsageLog
 *
 * Generic audit log for all AI LLM calls across the platform.
 */
class AIUsageLog extends DataObject {
    private static $table_name = 'AIUsageLog';
    private static $db = [
        'IdempotencyKey' => 'Varchar(255)',
        'Model' => 'Varchar(50)',
        'RequestType' => 'Enum("agent,content,search","agent")',
        'PromptTokens' => 'Int',
        'CompletionTokens' => 'Int',
        'TotalTokens' => 'Int',
        'Cost' => 'Decimal(10,6)',
        'RequestTime' => 'Datetime',
        'ResponseTime' => 'Datetime',
        'Success' => 'Boolean',
        'ErrorMessage' => 'Text',
        'InputCostPer1MAtTime' => 'Decimal(10,2)',
        'OutputCostPer1MAtTime' => 'Decimal(10,2)',
        'CacheWriteTokens' => 'Int',
        'CacheReadTokens' => 'Int',
        'ProviderRequestId' => 'Varchar(255)',
        'ErrorType' => 'Enum("credit_limit,api_error,validation,context_overflow,unknown","")',
        'UsedFreeCredits' => 'Decimal(10,6)',
        'UsedPaidCredits' => 'Decimal(10,6)',
        'CacheWriteCostPer1MAtTime' => 'Decimal(10,2)',
        'CacheReadCostPer1MAtTime' => 'Decimal(10,2)',
    ];
    private static $has_one = [
        'Member' => Member::class,
        'AIModel' => AIModel::class,
        'Entity' => DataObject::class,
    ];
    private static $indexes = [
        'IdempotencyKey' => [
            'type' => 'unique',
            'columns' => ['IdempotencyKey'],
        ],
        'Model' => true,
        'RequestTime' => true,
        'MemberID' => true,
        'AIModelID' => true,
        'EntityID' => true,
        'EntityClass' => true,
        'MemberID_RequestTime' => [
            'type' => 'index',
            'columns' => ['MemberID', 'RequestTime'],
        ],
    ];
    private static $summary_fields = [
        'RequestTime',
        'Member.Email',
        'AIModel.DisplayName',
        'Entity.Title',
        'RequestType',
        'PromptTokens' => 'Input',
        'CompletionTokens' => 'Output',
        'CacheWriteTokens' => 'Cache Write',
        'CacheReadTokens' => 'Cache Read',
        'TotalTokens' => 'Total',
        'FormattedCost' => 'Cost',
        'CircuitBreakerStatus' => 'CB',
        'Success',
    ];
    private static $default_sort = 'RequestTime DESC';

    public function getFormattedCost(): string {
        return '$' . number_format($this->Cost, 6);
    }

    public function canEdit($member = null): bool {
        return false;
    }

    public function canDelete($member = null): bool {
        return Permission::checkMember($member, 'ADMIN');
    }

    public function onBeforeWrite(): void {
        parent::onBeforeWrite();

        if (!$this->MemberID) {
            $this->MemberID = Security::getCurrentUser()?->ID;
        }

        if (!$this->AIModelID && $this->Model) {
            $aiModel = AIModel::get()->filter('Name', $this->Model)->first();
            if ($aiModel) {
                $this->AIModelID = $aiModel->ID;
            }
        }

        if (!$this->Cost && $this->AIModelID) {
            $aiModel = $aiModel ?? AIModel::get()->byID($this->AIModelID);
            if ($aiModel) {
                $this->Cost = $aiModel->calculateRequestCost([
                    'input_tokens' => $this->PromptTokens,
                    'output_tokens' => $this->CompletionTokens,
                    'cache_write_tokens' => $this->CacheWriteTokens,
                    'cache_read_tokens' => $this->CacheReadTokens,
                ]);
            }
        }
    }

    /**
     * Create and write a usage log record, returning the usage array for AgentResponse.
     */
    public static function record(
        Member     $member,
        AIModel    $model,
        DataObject $contextEntity,
        ?Usage     $usageObj,
        bool       $success,
        ?string    $errorMessage = null,
        string     $requestType = 'agent',
        int        $promptTokens = 0
    ): array {
        $usage = [
            'prompt_tokens' => $usageObj?->inputTokens ?? $promptTokens,
            'completion_tokens' => $usageObj?->outputTokens ?? 0,
            'total_tokens' => $usageObj?->getTotal() ?? $promptTokens,
            'cache_write_tokens' => $usageObj?->cacheWriteTokens ?? 0,
            'cache_read_tokens' => $usageObj?->cacheReadTokens ?? 0,
        ];

        $log = static::create();
        $log->RequestTime = date('Y-m-d H:i:s');
        $log->ResponseTime = date('Y-m-d H:i:s');
        $log->Model = $model->Name;
        $log->AIModelID = $model->ID;
        $log->RequestType = $requestType;
        $log->MemberID = $member->ID;
        $log->EntityID = $contextEntity->ID;
        $log->EntityClass = $contextEntity->ClassName;
        $log->PromptTokens = $usage['prompt_tokens'];
        $log->CompletionTokens = $usage['completion_tokens'];
        $log->TotalTokens = $usage['total_tokens'];
        $log->CacheWriteTokens = $usage['cache_write_tokens'];
        $log->CacheReadTokens = $usage['cache_read_tokens'];
        $log->Success = $success;
        if ($errorMessage) {
            $log->ErrorMessage = $errorMessage;
        }
        $log->write();

        return $usage;
    }

    public function requireDefaultRecords(): void {
        parent::requireDefaultRecords();
    }
}
