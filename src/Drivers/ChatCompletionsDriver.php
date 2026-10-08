<?php

namespace BetaNow\AiSeeder\Drivers;

use BetaNow\AiSeeder\Contracts\AiDriver;
use BetaNow\AiSeeder\Exceptions\GenerationFailed;
use BetaNow\AiSeeder\Exceptions\InvalidDefinition;
use BetaNow\AiSeeder\Exceptions\MissingApiKey;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Driver for any server speaking the OpenAI chat-completions wire format (OpenAI, Ollama, LM Studio, llama.cpp).
 *
 * @author BetaNow
 */
final class ChatCompletionsDriver implements AiDriver
{
    /**
     * @param string $name The configured driver name, used in error messages.
     * @param array<string, mixed> $config The driver configuration from ai-seeder.drivers.<name>.
     */
    public function __construct (private string $name, private array $config)
    {
    }

    public function complete (string $system, string $user): string
    {
        $this->ensureConfigured();

        return $this->extract($this->send($system, $user));
    }

    public function completeMany (array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $this->ensureConfigured();

        $results = Http::pool(function (Pool $pool) use ($requests) {
            foreach ($requests as $key => $request) {
                $this->configure($pool->as((string)$key))
                    ->post($this->endpoint(), $this->payload($request['system'], $request['user']));
            }
        });

        $contents = [];
        $failed = [];

        // First pass: keep the successes and fail right away on anything that a retry cannot fix.
        foreach ($requests as $key => $request) {
            $result = $results[$key] ?? NULL;

            if ($result instanceof Response) {
                if ($result->successful()) {
                    $contents[$key] = $this->extract($result);

                    continue;
                }

                if (! $this->isRetryable($result->status())) {
                    throw $this->httpError($result);
                }
            }

            $failed[$key] = $result;
        }

        // Second pass: only now retry the items that failed retryably, one after the other.
        foreach ($failed as $key => $result) {
            $contents[$key] = $this->retryPooled($requests[$key], $result);
        }

        $ordered = [];

        foreach ($requests as $key => $request) {
            $ordered[$key] = $contents[$key];
        }

        return $ordered;
    }

    /**
     * Retries one item whose pooled attempt failed retryably. The pooled attempt counts as the first attempt, so at
     * most `retries` more requests are sent, waiting the same exponential delays as a direct call.
     *
     * @param array{system: string, user: string} $request
     */
    private function retryPooled (array $request, mixed $failure): string
    {
        $delays = $this->backoff();

        if ($delays === []) {
            throw $this->pooledFailure($failure);
        }

        $this->pause(array_shift($delays));

        return $this->extract($this->send($request['system'], $request['user'], $delays));
    }

    private function pooledFailure (mixed $failure): GenerationFailed
    {
        if ($failure instanceof Response) {
            return $this->httpError($failure);
        }

        $reason = $failure instanceof Throwable ? $failure->getMessage() : 'no response was received';

        return GenerationFailed::transport($this->name, $this->redact($reason));
    }

    /**
     * @param array<int, int>|null $delays Milliseconds to wait before each retry, or NULL for the configured backoff.
     */
    private function send (string $system, string $user, ?array $delays = NULL): Response
    {
        $request = $this->configure(Http::asJson());
        $delays ??= $this->backoff();

        if ($delays !== []) {
            $request = $request->retry($delays, when: fn (?Throwable $e) => $e !== NULL && $this->shouldRetry($e), throw: FALSE);
        }

        try {
            $response = $request->post($this->endpoint(), $this->payload($system, $user));
        } catch (ConnectionException|TransferException $e) {
            // Laravel wraps Guzzle's transfer errors in a ConnectionException, except on some Laravel 12 releases.
            throw GenerationFailed::transport($this->name, $this->redact($e->getMessage()));
        }

        // Not only failed(): 1xx and 3xx are neither successful nor failed, and must not reach extract().
        if (! $response->successful()) {
            throw $this->httpError($response);
        }

        return $response;
    }

    private function pause (int $milliseconds): void
    {
        if ($milliseconds > 0) {
            Sleep::usleep($milliseconds * 1000);
        }
    }

    private function configure (PendingRequest $request): PendingRequest
    {
        $request = $request->acceptJson()->asJson()->timeout(max(1, (int)($this->config['timeout'] ?? 60)));
        $key = $this->config['api_key'] ?? NULL;

        return filled($key) ? $request->withToken((string)$key) : $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload (string $system, string $user): array
    {
        $payload = [
            'model' => (string)$this->config['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (! empty($this->config['json_mode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    private function extract (Response $response): string
    {
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            $refusal = $response->json('choices.0.message.refusal');

            throw GenerationFailed::emptyContent($this->name, is_string($refusal) ? $this->snippet($refusal) : NULL);
        }

        return $content;
    }

    private function httpError (Response $response): GenerationFailed
    {
        $message = $response->json('error.message');
        $reason = $this->snippet(is_string($message) && trim($message) !== '' ? $message : $response->body());

        return GenerationFailed::http($this->name, $response->status(), $reason);
    }

    private function shouldRetry (Throwable $e): bool
    {
        if ($e instanceof ConnectionException || $e instanceof TransferException) {
            return TRUE;
        }

        return $e instanceof RequestException && $this->isRetryable($e->response->status());
    }

    private function isRetryable (int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * @return array<int, int> Milliseconds to wait before each retry, doubling every time.
     */
    private function backoff (): array
    {
        $retries = (int)($this->config['retries'] ?? 3);
        $delay = max(0, (int)($this->config['retry_delay'] ?? 500));

        if ($retries < 1) {
            return [];
        }

        return array_map(fn (int $attempt) => $delay * (2 ** $attempt), range(0, $retries - 1));
    }

    private function endpoint (): string
    {
        return (string)$this->config['endpoint'];
    }

    private function ensureConfigured (): void
    {
        if (! empty($this->config['requires_api_key']) && blank($this->config['api_key'] ?? NULL)) {
            throw MissingApiKey::forDriver($this->name);
        }

        if (blank($this->config['endpoint'] ?? NULL) || blank($this->config['model'] ?? NULL)) {
            throw InvalidDefinition::because("The [{$this->name}] AI driver needs both an endpoint and a model in ai-seeder.drivers.{$this->name}.");
        }
    }

    /**
     * Redacts the key first, then caps the length, so a key straddling the cut cannot leave a readable prefix.
     */
    private function snippet (string $text): string
    {
        return Str::limit($this->redact(trim($text)), 200);
    }

    private function redact (string $text): string
    {
        $key = (string)($this->config['api_key'] ?? '');

        return $key === '' ? $text : str_replace($key, '[redacted]', $text);
    }
}
