<?php

namespace Hudhaifas\AI\Extension;

use Hudhaifas\AI\Exception\CreditLimitExceededException;
use Hudhaifas\AI\Exception\LLMServiceUnavailableException;
use Hudhaifas\AI\Model\AIModel;
use Hudhaifas\AI\Model\AIUsageLog;
use InvalidArgumentException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * MemberAIExtension
 *
 * Provides AI credit management and model selection for members.
 *
 * CREDIT ARCHITECTURE:
 * - AIFreeMonthlyCredits: SHARED pool across all AI features (resets monthly)
 * - AIPurchasedCredits: Never-expiring credits purchased by user (agent/chatbot only)
 *
 * TWO-METHOD PATTERN (for agent/chatbot):
 * 1. getModelAndCalculateCost(): Pure calculation that returns model + credit split (NO DB write)
 * 2. deductCredits(): Simple deduction with pre-calculated amounts (ATOMIC DB write)
 */
class MemberAIExtension extends Extension {
    private static $db = [
        'AIFreeMonthlyCredits' => 'Decimal(10,6)',
        'AIPurchasedCredits' => 'Decimal(10,6)',
    ];
    private static $has_one = [
        'AIAgentModelOverride' => AIModel::class,
    ];
    private static $defaults = [
        'AIPurchasedCredits' => 0.00,
    ];
    private static $monthly_free_credits = 2.00;
    private static $has_many = [
        'AIUsageLogs' => AIUsageLog::class,
    ];

    public function updateCMSFields(FieldList $fields): void {
        if (!Permission::check('ADMIN')) {
            return;
        }

        $monthlyCredits = $this->owner->config()->get('monthly_free_credits');
        $dailyUsageData = $this->getDailyUsageData(30);

        $fields->removeByName(['AIFreeMonthlyCredits', 'AIPurchasedCredits', 'AIAgentModelOverrideID']);

        $fields->addFieldsToTab('Root.AICredits', [
            HeaderField::create('AICreditsHeader', 'AI Credits Management'),

            LiteralField::create('UsageCharts', $this->renderUsageCharts($dailyUsageData)),
            LiteralField::create('ChartSpacer', '<div style="margin-bottom: 30px;"></div>'),

            HeaderField::create('CreditsHeader', 'Free Monthly Credits', 3),

            FieldGroup::create(
                NumericField::create('AIFreeMonthlyCredits', '')
                    ->setScale(6)
                    ->setAttribute('min', '0'),
                LiteralField::create(
                    'FreeCreditsRefillButton',
                    sprintf(
                        '<button type="button" class="btn btn-outline-secondary" style="margin-left: 10px;" onclick="
                            var field = document.querySelector(\'input[name=\\\'AIFreeMonthlyCredits\\\']\');
                            if (field && confirm(\'Set free credits to %s?\')) {
                                field.value = \'%s\';
                                field.dispatchEvent(new Event(\'change\', { bubbles: true }));
                            }
                        ">Quick Refill to %s</button>',
                        $monthlyCredits,
                        $monthlyCredits,
                        $monthlyCredits
                    )
                )
            )
                ->setTitle('Credits')
                ->setDescription('Shared pool for all AI features. Resets on 1st of each month.'),

            HeaderField::create('PurchasedCreditsHeader', 'Purchased Credits', 3),

            NumericField::create('AIPurchasedCredits', 'Credits (Editable)')
                ->setDescription('Agent/chatbot only. Never expires.')
                ->setScale(6)
                ->setAttribute('min', '0'),

            ReadonlyField::create('TotalCreditsDisplay', 'Total Credits Available')
                ->setValue($this->getTotalCreditsAvailable()),

            HeaderField::create('ModelOverrideHeader', 'Model Configuration', 3),

            DropdownField::create(
                'AIAgentModelOverrideID',
                'Agent Model Override (Paid Tier)',
                AIModel::get()->filter('Active', true)->map('ID', 'Title')
            )
                ->setEmptyString('-- Use default paid model --')
                ->setDescription('Override the default paid model for this user'),
        ]);
    }

    private function renderUsageCharts(array $data): string {
        $chartData = json_encode($data);
        $totalRequests = array_sum($data['requests']);
        $formattedCost = number_format(array_sum($data['costs']), 2);
        $totalTokens = array_sum($data['inputTokens']) + array_sum($data['outputTokens'])
            + array_sum($data['cacheWriteTokens']) + array_sum($data['cacheReadTokens']);
        $formattedTokens = number_format($totalTokens);

        return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<div class="dashboard-panel member-ai-usage">
    <h3>Usage Statistics (Last 30 Days)</h3>
    <div class="row" style="margin-bottom:20px">
        <div class="col-12 col-md-4"><h5>Total Requests</h5><p class="metric-value">{$totalRequests}</p></div>
        <div class="col-12 col-md-4"><h5>Total Cost</h5><p class="metric-value">\${$formattedCost}</p></div>
        <div class="col-12 col-md-4"><h5>Total Tokens</h5><p class="metric-value">{$formattedTokens}</p></div>
    </div>
    <canvas id="memberUsageChart" style="max-height:300px"></canvas>
</div>
<script>
(function(){
    var d={$chartData};
    var ctx=document.getElementById('memberUsageChart');
    if(ctx&&d.dates.length>0){
        new Chart(ctx,{type:'line',data:{labels:d.dates,datasets:[
            {label:'Cost ($)',data:d.costs,borderColor:'rgb(75,192,192)',yAxisID:'y',tension:0.3,fill:true},
            {label:'Input Tokens',data:d.inputTokens,borderColor:'rgb(54,162,235)',yAxisID:'y1',tension:0.3,fill:true},
            {label:'Output Tokens',data:d.outputTokens,borderColor:'rgb(255,159,64)',yAxisID:'y1',tension:0.3,fill:true},
            {label:'Cache Write',data:d.cacheWriteTokens,borderColor:'rgb(255,99,132)',yAxisID:'y1',tension:0.3,fill:true},
            {label:'Cache Read',data:d.cacheReadTokens,borderColor:'rgb(153,102,255)',yAxisID:'y1',tension:0.3,fill:true}
        ]},options:{responsive:true,scales:{
            y:{type:'linear',position:'left',title:{display:true,text:'Cost ($)'}},
            y1:{type:'linear',position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Tokens'}}
        }}});
    }
})();
</script>
HTML;
    }

    private function getDailyUsageData(int $days = 30): array {
        $data = [
            'dates' => [], 'costs' => [], 'requests' => [],
            'inputTokens' => [], 'outputTokens' => [],
            'cacheWriteTokens' => [], 'cacheReadTokens' => [],
        ];

        $results = DB::prepared_query("
            SELECT DATE(RequestTime) as date, COUNT(*) as request_count,
                SUM(Cost) as total_cost, SUM(PromptTokens) as input_tokens,
                SUM(CompletionTokens) as output_tokens,
                SUM(CacheWriteTokens) as cache_write_tokens,
                SUM(CacheReadTokens) as cache_read_tokens
            FROM AIUsageLog
            WHERE MemberID = ? AND RequestTime >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(RequestTime) ORDER BY date ASC
        ", [$this->owner->ID, $days]);

        $usageMap = [];
        foreach ($results as $row) {
            $usageMap[$row['date']] = [
                'cost' => (float)$row['total_cost'],
                'requests' => (int)$row['request_count'],
                'inputTokens' => (int)$row['input_tokens'],
                'outputTokens' => (int)$row['output_tokens'],
                'cacheWriteTokens' => (int)$row['cache_write_tokens'],
                'cacheReadTokens' => (int)$row['cache_read_tokens'],
            ];
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $u = $usageMap[$date] ?? null;
            $data['dates'][] = date('M j', strtotime($date));
            $data['costs'][] = $u['cost'] ?? 0;
            $data['requests'][] = $u['requests'] ?? 0;
            $data['inputTokens'][] = $u['inputTokens'] ?? 0;
            $data['outputTokens'][] = $u['outputTokens'] ?? 0;
            $data['cacheWriteTokens'][] = $u['cacheWriteTokens'] ?? 0;
            $data['cacheReadTokens'][] = $u['cacheReadTokens'] ?? 0;
        }

        return $data;
    }

    public function validate(ValidationResult $validationResult): void {
        if ($this->owner->AIPurchasedCredits < 0) {
            $validationResult->addError('Purchased credits cannot be negative');
        }
        if ($this->owner->AIFreeMonthlyCredits < 0) {
            $validationResult->addError('Free monthly credits cannot be negative');
        }
    }

    public function getUserTokensUsed(string $period = 'month'): int {
        $query = $this->owner->AIUsageLogs();
        if ($period === 'today') {
            $query = $query->where("DATE(RequestTime) = CURDATE()");
        } elseif ($period === 'month') {
            $query = $query->where("RequestTime >= DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        }
        return $query->sum('TotalTokens') ?: 0;
    }

    public function getAICostUsed(string $period = 'month'): float {
        $query = $this->owner->AIUsageLogs();
        if ($period === 'today') {
            $query = $query->where("DATE(RequestTime) = CURDATE()");
        } elseif ($period === 'month') {
            $query = $query->where("RequestTime >= DATE_SUB(NOW(), INTERVAL 1 MONTH)");
        }
        return (float)($query->sum('Cost') ?: 0);
    }

    public function getAIDollarLimit(): float {
        if ($this->owner->AIFreeMonthlyCredits > 0) {
            return (float)$this->owner->AIFreeMonthlyCredits;
        }
        return (float)(Environment::getEnv('AI_DEFAULT_DOLLAR_LIMIT') ?: 10.00);
    }

    public function getAIDollarsRemaining(): float {
        return max(0, $this->getAIDollarLimit() - $this->getAICostUsed('month'));
    }

    public function hasUnlimitedAICredits(): bool {
        return Permission::check('ADMIN', 'any', $this->owner);
    }


    // ========================================
    // TWO-METHOD PATTERN (for agent/chatbot)
    // ========================================

    /**
     * Calculate credit split for a given cost without writing to DB.
     *
     * @throws CreditLimitExceededException
     */
    public function getCreditSplitForCost(AIModel $model, float $cost): array {
        if ($cost < 0) {
            throw new InvalidArgumentException('Cost cannot be negative');
        }
        if ($cost == 0 || $this->hasUnlimitedAICredits()) {
            return ['purchasedCredit' => 0.0, 'freeCredit' => 0.0];
        }

        $availablePurchased = (float)$this->owner->AIPurchasedCredits;
        $availableFree = (float)$this->owner->AIFreeMonthlyCredits;

        if ($model->canUseWithFreeCredits()) {
            $totalAvailable = $availablePurchased + $availableFree;
            if ($totalAvailable < $cost) {
                throw new CreditLimitExceededException(
                    "Insufficient credits. Required: \${$cost}, Available: \${$totalAvailable}.",
                    $totalAvailable,
                    $cost
                );
            }
            $usedPurchased = min($availablePurchased, $cost);
            return [
                'purchasedCredit' => round($usedPurchased, 6),
                'freeCredit' => round($cost - $usedPurchased, 6),
            ];
        }

        if ($availablePurchased < $cost) {
            throw new CreditLimitExceededException(
                "Insufficient purchased credits for paid model '{$model->DisplayName}'. Required: \${$cost}, Available: \${$availablePurchased}.",
                $availablePurchased,
                $cost
            );
        }
        return ['purchasedCredit' => round($cost, 6), 'freeCredit' => 0.0];
    }

    /**
     * Determine model and calculate credit split (pure calculation, no DB write).
     *
     * @throws LLMServiceUnavailableException
     * @throws CreditLimitExceededException
     */
    public function getModelAndCalculateCost(int $inputTokens, int $outputTokens): array {
        $siteConfig = SiteConfig::current_site_config();

        $paidModel = $this->owner->AIAgentModelOverride()->exists()
            ? $this->owner->AIAgentModelOverride()
            : $siteConfig->DefaultPaidAIModel();
        $freeModel = $siteConfig->DefaultFreeAIModel();

        if (!$paidModel || !$paidModel->exists()) {
            throw new LLMServiceUnavailableException("Paid model not configured");
        }
        if (!$freeModel || !$freeModel->exists()) {
            throw new LLMServiceUnavailableException("Free model not configured");
        }

        $paidCosts = $paidModel->getTokenCosts();
        $freeCosts = $freeModel->getTokenCosts();

        $paidCost = ($inputTokens / 1_000_000) * $paidCosts['input']
            + ($outputTokens / 1_000_000) * $paidCosts['output'];
        $freeCost = ($inputTokens / 1_000_000) * $freeCosts['input']
            + ($outputTokens / 1_000_000) * $freeCosts['output'];

        if ($this->hasUnlimitedAICredits()) {
            return ['model' => $paidModel, 'purchasedCredit' => 0.0, 'freeCredit' => 0.0];
        }

        $availablePurchased = (float)$this->owner->AIPurchasedCredits;
        $availableFree = (float)$this->owner->AIFreeMonthlyCredits;

        if ($availablePurchased >= $paidCost) {
            return ['model' => $paidModel, 'purchasedCredit' => $paidCost, 'freeCredit' => 0.0];
        }

        if (($availablePurchased + $availableFree) >= $freeCost) {
            $usedPurchased = min($availablePurchased, $freeCost);
            return [
                'model' => $freeModel,
                'purchasedCredit' => $usedPurchased,
                'freeCredit' => $freeCost - $usedPurchased,
            ];
        }

        $totalAvailable = $availablePurchased + $availableFree;
        throw new CreditLimitExceededException(
            "Insufficient credits. Required: \${$freeCost}, Available: \${$totalAvailable}.",
            $totalAvailable,
            $freeCost
        );
    }

    /**
     * Deduct credits atomically. Returns false if idempotency key already processed.
     */
    public function deductCredits(
        AIModel $model,
        float   $purchasedCredit,
        float   $freeCredit,
        string  $idempotencyKey
    ): bool {
        if ($this->hasUnlimitedAICredits()) {
            return true;
        }
        if (AIUsageLog::get()->filter(['IdempotencyKey' => $idempotencyKey])->exists()) {
            return false;
        }
        $this->owner->AIPurchasedCredits -= $purchasedCredit;
        $this->owner->AIFreeMonthlyCredits -= $freeCredit;
        $this->owner->write();
        return true;
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    public function getTotalCreditsAvailable(): float {
        return (float)$this->owner->AIFreeMonthlyCredits + (float)$this->owner->AIPurchasedCredits;
    }

    public function getFreeCreditsRemaining(): float {
        return (float)$this->owner->AIFreeMonthlyCredits;
    }

    public function getPurchasedCreditsRemaining(): float {
        return (float)$this->owner->AIPurchasedCredits;
    }

    public function addPurchasedCredits(float $amount): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
        $this->owner->AIPurchasedCredits += $amount;
        $this->owner->write();
    }

    public function refillFreeCredits(): void {
        $this->owner->AIFreeMonthlyCredits = $this->owner->config()->get('monthly_free_credits');
        $this->owner->write();
    }
}
