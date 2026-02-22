<?php

namespace Hudhaifas\AI\Tool;

use DateTime;
use DateTimeZone;
use NeuronAI\Tools\Tool;

/**
 * Tool to get the current date and time.
 *
 * Useful for:
 * - Calculating ages from birth dates
 * - Resolving relative date references ("3 days ago", "last week")
 * - Grounding the LLM in the current date context
 */
class GetCurrentDateTimeTool extends Tool {
    public function __construct() {
        parent::__construct(
            name: 'GetCurrentDateTime',
            description: 'Get the current date and time. Use this when you need to know today\'s date, calculate ages, or understand relative dates like "3 days ago" or "last year".',
            properties: []
        );
    }

    public function __invoke(): string {
        $now = new DateTime('now', new DateTimeZone('UTC'));

        return json_encode([
            'success' => true,
            'current_date' => $now->format('Y-m-d'),
            'current_datetime' => $now->format('Y-m-d H:i:s'),
            'current_year' => (int)$now->format('Y'),
            'current_month' => (int)$now->format('m'),
            'current_day' => (int)$now->format('d'),
            'timestamp' => $now->getTimestamp(),
            'timezone' => 'UTC',
            'day_of_week' => $now->format('l'),
        ], JSON_UNESCAPED_UNICODE);
    }
}
