<?php

namespace Hudhaifas\AI\Exception;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;

/**
 * Exception thrown when a user exceeds their AI credit limit.
 *
 * HTTP Status: 402 Payment Required
 */
class CreditLimitExceededException extends HTTPResponse_Exception {
    public function __construct(
        string $message = 'Credit limit exceeded. Please purchase more credits to continue using the AI assistant.',
        int    $code = 402
    ) {
        $response = new HTTPResponse($message, $code);
        parent::__construct($response, $code);
    }
}
