<?php

namespace Vendor\AiSeeder\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use ReflectionMethod;
use Vendor\AiSeeder\Drivers\ChatCompletionsDriver;
use Vendor\AiSeeder\Exceptions\GenerationFailed;
use Vendor\AiSeeder\Exceptions\MissingApiKey;
use Vendor\AiSeeder\Tests\TestCase;

class ChatCompletionsDriverTest extends TestCase
{
    public function test_it_posts_an_openai_style_request_and_returns_the_message_content (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->chatBody('{"rows": []}'))]);

        $content = $this->driver()->complete('system text', 'user text');

        $this->assertSame('{"rows": []}', $content);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'test-model'
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'system text'],
                ['role' => 'user', 'content' => 'user text'],
            ]
            && $request['response_format'] === ['type' => 'json_object']);
    }

    public function test_it_omits_the_auth_header_and_json_mode_when_not_configured (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->chatBody('ok'))]);

        $this->driver(['api_key' => NULL, 'json_mode' => FALSE, 'requires_api_key' => FALSE])->complete('s', 'u');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization') && ! isset($request['response_format']));
    }

    public function test_it_retries_server_errors_then_succeeds (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->pushStatus(503)->pushStatus(429)->push($this->chatBody('finally'))]);

        $this->assertSame('finally', $this->driver()->complete('s', 'u'));
        Http::assertSentCount(3);
    }

    public function test_it_gives_up_after_the_configured_retries (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('boom', 503)]);

        try {
            $this->driver()->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        Http::assertSentCount(3); // 1 attempt + 2 retries
    }

    public function test_it_does_not_retry_client_errors_and_never_leaks_the_key (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test']], 401)]);

        try {
            $this->driver()->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringContainsString('Incorrect API key provided', $e->getMessage());
            $this->assertStringNotContainsString('sk-test', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_wraps_connection_failures (): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('Could not resolve host');

        $this->driver(['retries' => 0])->complete('s', 'u');
    }

    // Review Focus 1: the provider answers 200 but there is nothing usable.
    public function test_it_reports_replies_without_usable_content_clearly (): void
    {
        $replies = [
            'no choices' => ['choices' => []],
            'empty body' => [],
            'null content' => ['choices' => [['message' => ['content' => NULL]]]],
            'blank content' => ['choices' => [['message' => ['content' => '   ']]]],
            'non-string content' => ['choices' => [['message' => ['content' => ['x']]]]],
        ];

        // Http::fake() keeps the first matching stub, so queue every reply in one sequence instead of re-faking.
        Http::preventStrayRequests();
        $sequence = Http::sequence();

        foreach ($replies as $body) {
            $sequence->push($body);
        }

        Http::fake(['*' => $sequence]);

        foreach ($replies as $label => $body) {
            try {
                $this->driver()->complete('s', 'u');
                $this->fail("Expected a GenerationFailed for: {$label}");
            } catch (GenerationFailed $e) {
                $this->assertStringContainsString('no message content', $e->getMessage(), $label);
            }
        }
    }

    public function test_it_surfaces_a_refusal (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => NULL, 'refusal' => 'I cannot help with that.']]]])]);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('I cannot help with that.');

        $this->driver()->complete('s', 'u');
    }

    public function test_it_fails_fast_without_a_required_api_key (): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            $this->driver(['api_key' => NULL])->complete('s', 'u');
            $this->fail('Expected a MissingApiKey.');
        } catch (MissingApiKey $e) {
            $this->assertStringContainsString('[openai]', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_complete_many_returns_contents_under_the_input_keys (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => fn (Request $request) => Http::response($this->chatBody('echo:' . $request['messages'][1]['content']))]);

        $results = $this->driver()->completeMany([
            'a' => ['system' => 's', 'user' => 'one'],
            'b' => ['system' => 's', 'user' => 'two'],
        ]);

        $this->assertSame(['a' => 'echo:one', 'b' => 'echo:two'], $results);
        Http::assertSentCount(2);
    }

    public function test_complete_many_of_nothing_makes_no_request (): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->assertSame([], $this->driver()->completeMany([]));
        Http::assertNothingSent();
    }

    public function test_complete_many_retries_a_failed_item_on_its_own (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->pushStatus(503)->push($this->chatBody('x'))->push($this->chatBody('y'))]);

        $results = $this->driver()->completeMany([
            'a' => ['system' => 's', 'user' => 'one'],
            'b' => ['system' => 's', 'user' => 'two'],
        ]);

        $values = array_values($results);
        sort($values);

        $this->assertSame(['x', 'y'], $values);
        $this->assertSame(['a', 'b'], array_keys($results));
        Http::assertSentCount(3);
    }

    public function test_complete_many_does_not_retry_a_client_error (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        try {
            $this->driver()->completeMany(['a' => ['system' => 's', 'user' => 'one']]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_it_reports_an_unfollowed_redirect_status_instead_of_crashing (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 304)]);

        try {
            $this->driver()->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 304', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_complete_many_reports_an_unfollowed_redirect_status (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 304)]);

        try {
            $this->driver()->completeMany(['a' => ['system' => 's', 'user' => 'one']]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 304', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_complete_backs_off_exponentially_between_retries (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('boom', 503)]);
        Sleep::fake();

        try {
            $this->driver(['retries' => 3, 'retry_delay' => 100])->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        Http::assertSentCount(4); // 1 attempt + 3 retries
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(200)->milliseconds(),
            Sleep::for(400)->milliseconds(),
        ]);
    }

    public function test_a_failing_pooled_item_gets_exactly_the_configured_retries_with_backoff (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('boom', 503)]);
        Sleep::fake();

        try {
            $this->driver(['retries' => 2, 'retry_delay' => 100])->completeMany(['a' => ['system' => 's', 'user' => 'one']]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        Http::assertSentCount(3); // 1 pooled attempt + 2 retries
        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(), // the first re-send waits too
            Sleep::for(200)->milliseconds(),
        ]);
    }

    public function test_complete_many_does_not_retry_when_retries_are_disabled (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('boom', 503)]);
        Sleep::fake();

        try {
            $this->driver(['retries' => 0, 'retry_delay' => 100])->completeMany(['a' => ['system' => 's', 'user' => 'one']]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_complete_many_surfaces_a_non_retryable_failure_before_retrying_anything (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()->pushStatus(503)->push(['error' => ['message' => 'nope']], 401)]);

        try {
            $this->driver()->completeMany([
                'a' => ['system' => 's', 'user' => 'one'],
                'b' => ['system' => 's', 'user' => 'two'],
            ]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        Http::assertSentCount(2); // item a is never retried
    }

    public function test_it_redacts_a_key_that_straddles_the_error_body_cut (): void
    {
        $key = 'sk-test-abcdefghij';

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(str_repeat('x', 192) . $key . ' tail', 400)]);

        try {
            $this->driver(['api_key' => $key])->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringNotContainsString($key, $e->getMessage());
            $this->assertStringNotContainsString(substr($key, 0, 6), $e->getMessage());
        }
    }

    public function test_it_redacts_and_caps_a_long_refusal (): void
    {
        $key = 'sk-test-abcdefghij';

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => NULL, 'refusal' => str_repeat('r', 192) . $key . str_repeat('z', 500)]]]])]);

        try {
            $this->driver(['api_key' => $key])->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringNotContainsString(substr($key, 0, 6), $e->getMessage());
            $this->assertStringNotContainsString('zzzz', $e->getMessage());
            $this->assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function test_it_retries_connection_failures_then_gives_up_without_leaking_the_key (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 7: could not connect with sk-test')
            ->pushFailedConnection('cURL error 7: could not connect with sk-test')
            ->pushFailedConnection('cURL error 7: could not connect with sk-test')]);

        try {
            $this->driver()->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('could not connect', $e->getMessage());
            $this->assertStringNotContainsString('sk-test', $e->getMessage());
        }

        Http::assertSentCount(3); // 1 attempt + 2 retries
    }

    public function test_it_retries_guzzle_transfer_failures_then_gives_up_without_leaking_the_key (): void
    {
        $message = 'cURL error 56: connection reset while using sk-test';
        $failures = [
            'bare transfer exception' => fn () => $this->transferException($message),
            'request exception without a response' => fn () => new GuzzleRequestException($message, new PsrRequest('POST', 'https://api.test')),
            'connect exception' => fn () => new ConnectException($message, new PsrRequest('POST', 'https://api.test')),
        ];
        $attempts = 0;
        $next = NULL;

        // Counted in the stub itself: Http::fake() does not record an exception that Laravel leaves unwrapped.
        Http::preventStrayRequests();
        Http::fake(function () use (&$attempts, &$next) {
            $attempts++;

            throw $next();
        });

        foreach ($failures as $label => $make) {
            $attempts = 0;
            $next = $make;

            try {
                $this->driver()->complete('s', 'u');
                $this->fail("Expected a GenerationFailed for: {$label}");
            } catch (GenerationFailed $e) {
                $this->assertStringContainsString('cURL error 56', $e->getMessage(), $label);
                $this->assertStringNotContainsString('sk-test', $e->getMessage(), $label);
            }

            $this->assertSame(3, $attempts, "{$label}: 1 attempt + 2 retries"); // retries are configured as 2
        }
    }

    public function test_a_guzzle_transfer_failure_in_a_pooled_item_is_retried_and_wrapped (): void
    {
        $attempts = 0;

        Http::preventStrayRequests();
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw $this->transferException('cURL error 56: connection reset while using sk-test');
        });

        try {
            $this->driver()->completeMany(['a' => ['system' => 's', 'user' => 'one']]);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('cURL error 56', $e->getMessage());
            $this->assertStringNotContainsString('sk-test', $e->getMessage());
        }

        $this->assertSame(3, $attempts); // 1 pooled attempt + 2 retries
    }

    public function test_it_redacts_and_caps_a_long_provider_error_message (): void
    {
        $key = 'sk-test-abcdefghij';
        // The key straddles the 200-character cut, and 5,000 more characters follow it.
        $message = 'Provider says: ' . str_repeat('m', 177) . $key . str_repeat('q', 5000);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => $message]], 400)]);

        try {
            $this->driver(['api_key' => $key])->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('Provider says: mmmm', $e->getMessage());
            $this->assertStringNotContainsString(substr($key, 0, 6), $e->getMessage());
            $this->assertStringNotContainsString('qqqq', $e->getMessage());
            $this->assertLessThan(400, strlen($e->getMessage()));
        }
    }

    public function test_a_blank_provider_error_message_falls_back_to_the_body (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => '   '], 'detail' => 'quota exceeded'], 400)]);

        try {
            $this->driver()->complete('s', 'u');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
        }
    }

    /**
     * A plain Guzzle TransferException. Guzzle 8 requires the request; Guzzle 7 has none, which is the one case that
     * older Laravel 12 releases leave unwrapped.
     */
    private function transferException (string $message): TransferException
    {
        return (new ReflectionMethod(TransferException::class, '__construct'))->getNumberOfRequiredParameters() > 1
            ? new TransferException($message, new PsrRequest('POST', 'https://api.test'))
            : new TransferException($message);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function driver (array $overrides = []): ChatCompletionsDriver
    {
        return new ChatCompletionsDriver('openai', array_merge([
            'api_key' => 'sk-test',
            'endpoint' => 'https://api.test/v1/chat/completions',
            'model' => 'test-model',
            'json_mode' => TRUE,
            'timeout' => 5,
            'retries' => 2,
            'retry_delay' => 0,
            'requires_api_key' => TRUE,
        ], $overrides));
    }
}
