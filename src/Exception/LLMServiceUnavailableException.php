<?php

namespace Hudhaifas\AI\Exception;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;

/**
 * Exception thrown when the LLM service is temporarily unavailable.
 *
 * HTTP Status: 503 Service Unavailable
 */
class LLMServiceUnavailableException extends HTTPResponse_Exception {
    public function __construct(
        string $message = 'The AI service is temporarily unavailable. Please try again later.'
    ) {
        $response = new HTTPResponse($message, 503);
        parent::__construct($response, 503);
    }
}
