<?php

namespace Hudhaifas\AI\Model;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

class AIModel extends DataObject {
    private static $table_name = 'AIModel';
    private static $db = [
        'Name' => 'Varchar(50)',
        'DisplayName' => 'Varchar(100)',
        'InputCostPer1M' => 'Decimal(10,2)',
        'OutputCostPer1M' => 'Decimal(10,2)',
        'CacheWriteCostPer1M' => 'Decimal(10,6)',  // Cache creation/setup (~25% premium)
        'CacheReadCostPer1M' => 'Decimal(10,6)',   // Cache hits (~90% discount)
        'Active' => 'Boolean',
        'Provider' => 'Varchar(50)',
    ];
    private static $has_many = [
        'UsageLogs' => AIUsageLog::class
    ];
    private static $defaults = [
        'Active' => true,
        'Provider' => 'OpenAI',
    ];
    private static $summary_fields = [
        'DisplayName',
        'Name',
        'Provider',
        'FormattedInputCost' => 'Input Cost',
        'FormattedOutputCost' => 'Output Cost',
        'FormattedAverageCost' => 'Avg Cost',
        'Active'
    ];
    private static $default_sort = '(InputCostPer1M + OutputCostPer1M) ASC';

    /**
     * Get formatted input cost with $ sign
     *
     * @return string
     */
    public function getFormattedInputCost(): string {
        return '$' . number_format($this->InputCostPer1M, 2);
    }

    /**
     * Get formatted output cost with $ sign
     *
     * @return string
     */
    public function getFormattedOutputCost(): string {
        return '$' . number_format($this->OutputCostPer1M, 2);
    }

    /**
     * Get formatted average cost with $ sign
     *
     * @return string
     */
    public function getFormattedAverageCost(): string {
        return '$' . number_format($this->getAverageCostPer1M(), 2);
    }

    /**
     * Get title for dropdown lists showing name and costs
     * Format: "DisplayName (In: $X.XX / Out: $Y.YY)"
     *
     * @return string
     */
    public function getTitle(): string {
        return sprintf(
            '%s (In: $%s / Out: $%s)',
            $this->DisplayName,
            number_format($this->InputCostPer1M, 2),
            number_format($this->OutputCostPer1M, 2)
        );
    }

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        // Model Configuration

        // Provider dropdown with available providers
        $providerField = $fields->dataFieldByName('Provider');
        if ($providerField) {
            $fields->replaceField('Provider',
                DropdownField::create('Provider', 'Provider', [
                    'OpenAI' => 'OpenAI',
                    'Anthropic' => 'Anthropic',
                    'Google' => 'Google (Gemini)',
                    'Meta' => 'Meta (Llama)',
                    'Mistral' => 'Mistral AI',
                    'Cohere' => 'Cohere'
                ])
                    ->setDescription('AI model provider')
                    ->setEmptyString('-- Select Provider --')
            );
        }

        $this->addUsageChartsTab($fields);

        return $fields;
    }

    /**
     * Get daily usage data for this model
     *
     * @param int $days Number of days to retrieve
     * @return array
     */
    protected function addUsageChartsTab($fields): void {
        if ($this->exists()) {
            $fields->addFieldToTab('Root.UsageStatistics',
                LiteralField::create('UsageCharts', $this->renderUsageCharts($this->getDailyUsageData(30)))
            );
        }
    }

    public function getDailyUsageData(int $days = 30): array {
        $sql = "
            SELECT
                DATE(RequestTime) as date,
                COUNT(*) as request_count,
                SUM(Cost) as total_cost,
                SUM(PromptTokens) as input_tokens,
                SUM(CompletionTokens) as output_tokens,
                SUM(CacheWriteTokens) as cache_write_tokens,
                SUM(CacheReadTokens) as cache_read_tokens
            FROM AIUsageLog
            WHERE AIModelID = ?
            AND RequestTime >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(RequestTime)
            ORDER BY date ASC
        ";

        $results = DB::prepared_query($sql, [$this->ID, $days]);

        $data = [
            'dates' => [],
            'requests' => [],
            'costs' => [],
            'inputTokens' => [],
            'outputTokens' => [],
            'cacheWriteTokens' => [],
            'cacheReadTokens' => []
        ];

        foreach ($results as $row) {
            $data['dates'][] = date('M j', strtotime($row['date']));
            $data['requests'][] = (int)$row['request_count'];
            $data['costs'][] = (float)$row['total_cost'];
            $data['inputTokens'][] = (int)$row['input_tokens'];
            $data['outputTokens'][] = (int)$row['output_tokens'];
            $data['cacheWriteTokens'][] = (int)$row['cache_write_tokens'];
            $data['cacheReadTokens'][] = (int)$row['cache_read_tokens'];
        }

        return $data;
    }

    /**
     * Render usage charts HTML
     *
     * @param array $data
     * @return string
     */
    protected function renderUsageCharts(array $data): string {
        $chartData = json_encode($data);
        $totalCost = number_format($this->UsageLogs()->sum('Cost'), 2);
        $totalTokens = number_format($this->UsageLogs()->sum('TotalTokens'));

        return <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<div class="dashboard-panel ai-model-usage">
    <h3>{$this->DisplayName} - Usage Statistics (Last 30 Days)</h3>

    <div class="row" style="margin-bottom: 20px;">
        <div class="col-12 col-md-4">
            <h5>Total Requests</h5>
            <p class="metric-value">{$this->UsageLogs()->count()}</p>
        </div>
        <div class="col-12 col-md-4">
            <h5>Total Cost</h5>
            <p class="metric-value">\${$totalCost}</p>
        </div>
        <div class="col-12 col-md-4">
            <h5>Total Tokens</h5>
            <p class="metric-value">{$totalTokens}</p>
        </div>
    </div>

    <hr>

    <div class="row">
        <div class="col-md-12">
            <div style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 4px; position: relative;">
                <h5 style="margin-top: 0; display: inline-block;">Daily Usage Trends (Cost & Tokens)</h5>
                <button id="expandModelChartBtn" style="float: right; padding: 5px 10px; cursor: pointer; border: 1px solid #ddd; background: white; border-radius: 3px;" title="Expand to fullscreen">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                        <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
                    </svg>
                </button>
                <div style="clear: both;"></div>
                <canvas id="modelUsageChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Fullscreen Modal -->
    <div id="modelChartModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; padding: 20px;">
        <div style="position: relative; width: 100%; height: 100%; background: white; border-radius: 8px; padding: 20px;">
            <button id="closeModelChartBtn" style="position: absolute; top: 10px; right: 10px; padding: 8px 12px; cursor: pointer; border: 1px solid #ddd; background: white; border-radius: 3px; z-index: 10000;" title="Close fullscreen">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                    <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8 2.146 2.854Z"/>
                </svg>
            </button>
            <h3 style="margin-top: 0;">{$this->DisplayName} - Daily Usage Trends (Last 30 Days)</h3>
            <canvas id="modelUsageChartFullscreen" style="width: 100%; height: calc(100% - 60px);"></canvas>
        </div>
    </div>
</div>

<script>
(function() {
    var chartData = {$chartData};

    // Daily Usage Chart (Cost + Input/Output/Cache Tokens)
    var usageCtx = document.getElementById('modelUsageChart');
    var modelUsageChartInstance = null;
    if (usageCtx && chartData.dates.length > 0) {
        modelUsageChartInstance = new Chart(usageCtx, {
            type: 'line',
            data: {
                labels: chartData.dates,
                datasets: [
                    {
                        label: 'Cost (\$)',
                        data: chartData.costs,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        yAxisID: 'y',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Input Tokens',
                        data: chartData.inputTokens,
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Output Tokens',
                        data: chartData.outputTokens,
                        borderColor: 'rgb(255, 159, 64)',
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Cache Write',
                        data: chartData.cacheWriteTokens,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Cache Read',
                        data: chartData.cacheReadTokens,
                        borderColor: 'rgb(153, 102, 255)',
                        backgroundColor: 'rgba(153, 102, 255, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: window.innerWidth < 768 ? 1 : 2,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    if (context.dataset.label === 'Cost (\$)') {
                                        label += '\$' + context.parsed.y.toFixed(3);
                                    } else {
                                        label += context.parsed.y.toLocaleString();
                                    }
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Cost (\$)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '\$' + value.toFixed(2);
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Tokens'
                        },
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000) {
                                    return (value / 1000000).toFixed(1) + 'M';
                                } else if (value >= 1000) {
                                    return (value / 1000).toFixed(1) + 'K';
                                }
                                return value;
                            }
                        }
                    }
                }
            }
        });
    }

    // Fullscreen functionality
    var expandBtn = document.getElementById('expandModelChartBtn');
    var closeBtn = document.getElementById('closeModelChartBtn');
    var modal = document.getElementById('modelChartModal');
    var fullscreenCanvas = document.getElementById('modelUsageChartFullscreen');
    var fullscreenChartInstance = null;

    if (expandBtn && modelUsageChartInstance) {
        expandBtn.addEventListener('click', function() {
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Clone the chart config from the existing chart
            var config = modelUsageChartInstance.config;
            var fullscreenConfig = {
                type: config.type,
                data: JSON.parse(JSON.stringify(config.data)),
                options: JSON.parse(JSON.stringify(config.options))
            };
            fullscreenConfig.options.maintainAspectRatio = false;

            if (fullscreenChartInstance) {
                fullscreenChartInstance.destroy();
            }
            fullscreenChartInstance = new Chart(fullscreenCanvas, fullscreenConfig);
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            if (fullscreenChartInstance) {
                fullscreenChartInstance.destroy();
                fullscreenChartInstance = null;
            }
        });
    }

    // Close on ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display === 'block') {
            closeBtn.click();
        }
    });

    // Close on background click
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeBtn.click();
            }
        });
    }
})();
</script>
HTML;
    }

    public function getTotalUsage(string $period = 'month'): array {
        $query = $this->UsageLogs();

        switch ($period) {
            case 'today':
                $query = $query->where("DATE(RequestTime) = CURDATE()");
                break;
            case 'month':
                $query = $query->where("RequestTime >= DATE_SUB(NOW(), INTERVAL 1 MONTH)");
                break;
        }

        return [
            'tokens' => $query->sum('TotalTokens') ?: 0,
            'cost' => (float)($query->sum('Cost') ?: 0),
            'requests' => $query->count()
        ];
    }

    /**
     * Get average cost per 1M tokens (input + output) / 2
     * Useful for comparing models at a glance
     *
     * @return float
     */
    public function getAverageCostPer1M(): float {
        return ($this->InputCostPer1M + $this->OutputCostPer1M) / 2;
    }

    /**
     * Get token costs for this model instance
     *
     * @return array ['input' => float, 'output' => float]
     */
    public function getTokenCosts(): array {
        return [
            'input' => (float)$this->InputCostPer1M,
            'output' => (float)$this->OutputCostPer1M,
        ];
    }

    /**
     * Calculate request cost from usage metrics
     *
     * Provider-agnostic calculation based on presence of cache metrics.
     * Checks for data presence, not provider name, maintaining polymorphism.
     *
     * Usage array structure (normalized by provider):
     * - input_tokens: Standard/dynamic tokens only (NOT including cache writes)
     * - cache_write_tokens: Tokens written to cache (setup cost)
     * - cache_read_tokens: Tokens read from cache (hit cost)
     * - output_tokens: Response tokens
     *
     * These buckets are mutually exclusive - a token is counted in exactly one bucket.
     *
     * @param array $usage Normalized usage metrics from provider
     * @return float Total cost in dollars (6 decimal precision)
     */
    public function calculateRequestCost(array $usage): float {
        $cost = 0.0;

        // 1. Output Cost (Universal)
        if (isset($usage['output_tokens'])) {
            $cost += ($usage['output_tokens'] / 1_000_000) * $this->OutputCostPer1M;
        }

        // 2. Cache Read Cost (The "Hit" - 90% discount)
        if (!empty($usage['cache_read_tokens'])) {
            $cost += ($usage['cache_read_tokens'] / 1_000_000) * $this->CacheReadCostPer1M;
        }

        // 3. Cache Write Cost (The "Miss/Setup" - 25% premium)
        if (!empty($usage['cache_write_tokens'])) {
            $cost += ($usage['cache_write_tokens'] / 1_000_000) * $this->CacheWriteCostPer1M;
        }

        // 4. Standard Input Cost (Dynamic/Remainder)
        if (!empty($usage['input_tokens'])) {
            $cost += ($usage['input_tokens'] / 1_000_000) * $this->InputCostPer1M;
        }

        return round($cost, 6);
    }

    public static function getActiveModels(): array {
        return self::get()->filter('Active', true)->map('Name', 'Title')->toArray();
    }

    /**
     * Get token costs for all active models (static method for backward compatibility)
     *
     * @return array Map of model names to cost arrays
     */
    public static function getTokenCostsForModel(string $modelName = null): ?array {
        if ($modelName) {
            $model = self::get()->filter(['Active' => true, 'Name' => $modelName])->first();
            if ($model) {
                return [
                    'input' => (float)$model->InputCostPer1M,
                    'output' => (float)$model->OutputCostPer1M
                ];
            }
            return null;
        }

        // Return all models if no specific model requested
        $costs = [];
        foreach (self::get()->filter('Active', true) as $model) {
            $costs[$model->Name] = [
                'input' => (float)$model->InputCostPer1M,
                'output' => (float)$model->OutputCostPer1M
            ];
        }
        return $costs;
    }
}
