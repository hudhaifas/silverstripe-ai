<?php

namespace Hudhaifas\AI\Provider;

use Hudhaifas\AI\Chat\Messages\CachedUsage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\Anthropic\Anthropic;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * CachedAnthropic Provider
 *
 * Temporary local extension of NeuronAI's Anthropic provider that adds prompt
 * caching support while https://github.com/neuron-core/neuron-ai/pull/482 is pending.
 *
 * Mirrors the PR API exactly so migration is a one-line swap once merged:
 *   - Replace `new CachedAnthropic(...)` with `new Anthropic(...)`
 *   - Delete this file and CachedUsage.php (cache fields will be on base Usage)
 *
 * API (matches upstream PR):
 *   ->systemPromptBlocks(array $blocks)  — content blocks with optional cache_control
 *   ->withPromptCaching(bool $enabled)   — opt-in tool caching
 */
class CachedAnthropic extends Anthropic {
    /**
     * System prompt as content blocks (for prompt caching).
     */
    protected ?array $systemBlocks = null;
    /**
     * Whether to cache tool definitions.
     */
    protected bool $promptCachingEnabled = false;

    /**
     * Set system prompt as content blocks.
     *
     * @param array $blocks Content blocks with optional cache_control, e.g.:
     *   [
     *     ['type' => 'text', 'text' => $static, 'cache_control' => ['type' => 'ephemeral']],
     *     ['type' => 'text', 'text' => $dynamic],
     *   ]
     */
    public function systemPromptBlocks(array $blocks): self {
        $this->systemBlocks = $blocks;
        $this->system = null;
        return $this;
    }

    /**
     * Enable prompt caching for tools.
     * When enabled, the last tool is marked with cache_control: ephemeral.
     */
    public function withPromptCaching(bool $enabled = true): self {
        $this->promptCachingEnabled = $enabled;
        return $this;
    }

    /**
     * Override chat() to inject systemBlocks and tool caching into the payload.
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message {
        $json = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->systemBlocks)) {
            $json['system'] = $this->systemBlocks;
        } elseif (isset($this->system)) {
            $json['system'] = $this->system;
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
            if ($this->promptCachingEnabled) {
                $last = count($json['tools']) - 1;
                $json['tools'][$last]['cache_control'] = ['type' => 'ephemeral'];
            }
        }

        $response = $this->httpClient->request(
            HttpRequest::post(uri: 'messages', body: $json)
        );

        $result = $response->json();
        $message = $this->processChatResult($result);

        if (isset($result['usage'])) {
            $u = $result['usage'];
            $cacheCreation = $u['cache_creation'] ?? [];
            $cacheWrite = ($cacheCreation['ephemeral_5m_input_tokens'] ?? 0)
                + ($cacheCreation['ephemeral_1h_input_tokens'] ?? 0)
                + ($u['cache_creation_input_tokens'] ?? 0);
            $cacheRead = $u['cache_read_input_tokens'] ?? 0;

            Injector::inst()->get(LoggerInterface::class)->info('[CachedAnthropic] Cache metrics', [
                'cache_write_tokens' => $cacheWrite,
                'cache_read_tokens' => $cacheRead,
                'input_tokens' => $u['input_tokens'] ?? 0,
                'output_tokens' => $u['output_tokens'] ?? 0,
            ]);

            $message->setUsage(new CachedUsage(
                $u['input_tokens'] ?? 0,
                $u['output_tokens'] ?? 0,
                $cacheWrite,
                $cacheRead,
            ));
        }

        return $message;
    }
}
