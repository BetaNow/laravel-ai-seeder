<?php

namespace BetaNow\AiSeeder\Tests\Smoke;

use BetaNow\AiSeeder\Exceptions\GenerationFailed;
use BetaNow\AiSeeder\Facades\AiSeeder;
use BetaNow\AiSeeder\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

/**
 * Exercises the real HTTP client, Http::pool and the whole pipeline against a local fake server (no API key).
 */
#[Group('smoke')]
final class ChatCompletionsSmokeTest extends TestCase
{
    private static ?Process $server = NULL;

    private static int $port = 0;

    private static string $log = '';

    private static string $state = '';

    public static function setUpBeforeClass (): void
    {
        parent::setUpBeforeClass();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === FALSE) {
            throw new RuntimeException("Could not reserve a port: {$error}");
        }

        self::$port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, FALSE), ':'), 1);
        fclose($socket);

        self::$log = sys_get_temp_dir() . '/ai-seeder-smoke-' . getmypid() . '.jsonl';
        self::$state = sys_get_temp_dir() . '/ai-seeder-smoke-' . getmypid() . '.json';

        self::$server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Support/fake-openai-server.php'],
            NULL,
            ['PHP_CLI_SERVER_WORKERS' => '4', 'FAKE_OPENAI_LOG' => self::$log, 'FAKE_OPENAI_STATE' => self::$state]
        );
        self::$server->start();

        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (! self::$server->isRunning()) {
                $output = self::$server->getErrorOutput();
                self::stopServer();

                throw new RuntimeException('The fake OpenAI server exited early: ' . $output);
            }

            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);

            if ($connection !== FALSE) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        // PHPUnit may not run tearDownAfterClass() after an exception from here, so the server is stopped first.
        $output = self::$server->getErrorOutput();
        self::stopServer();

        throw new RuntimeException('The fake OpenAI server did not start: ' . $output);
    }

    public static function tearDownAfterClass (): void
    {
        self::stopServer();

        @unlink(self::$log);
        @unlink(self::$state);

        parent::tearDownAfterClass();
    }

    private static function stopServer (): void
    {
        $server = self::$server;
        self::$server = NULL;

        if ($server === NULL) {
            return;
        }

        $pid = $server->getPid();
        $workers = [];

        // With PHP_CLI_SERVER_WORKERS the workers outlive a stopped master, so note their pids before stopping it.
        if (is_int($pid) && $pid > 0) {
            $workers = array_filter(array_map('intval', explode("\n", (string)@shell_exec('pgrep -P ' . $pid . ' 2>/dev/null'))));
        }

        $server->stop();

        if ($workers !== []) {
            @exec('kill ' . implode(' ', $workers) . ' 2>/dev/null');
        }
    }

    protected function setUp (): void
    {
        parent::setUp();

        @unlink(self::$log);
        @unlink(self::$state);
    }

    public function test_it_seeds_rows_over_real_http (): void
    {
        $this->useServer();

        $created = AiSeeder::seed(Product::class, 25, 'local');

        $this->assertCount(25, $created);
        $this->assertSame(25, Product::count());
        $this->assertCount(3, $this->requestLog()); // batches of 10, 10 and 5

        foreach (Product::all() as $product) {
            $this->assertContains($product->category, ProductDefinition::CATEGORIES);
            $this->assertGreaterThanOrEqual(5, $product->price);
            $this->assertLessThanOrEqual(500, $product->price);
            $this->assertSame('EUR', $product->currency);
        }
    }

    public function test_it_accepts_json_wrapped_in_a_code_fence (): void
    {
        $this->useServer('/fenced');

        $this->assertCount(12, AiSeeder::seed(Product::class, 12, 'local'));
        $this->assertSame(12, Product::count());
    }

    public function test_it_recovers_from_a_flaky_server (): void
    {
        $this->useServer('/flaky');

        $this->assertCount(25, AiSeeder::seed(Product::class, 25, 'local'));

        $failures = array_filter($this->requestLog(), fn (array $entry) => $entry['status'] === 500);
        $this->assertCount(2, $failures);
    }

    public function test_batches_are_sent_concurrently (): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The PHP built-in server cannot run workers on Windows.');
        }

        $this->useServer('/slow');

        // The built-in server's workers share one listening socket, so one worker may occasionally accept several
        // pending connections and serve them one by one. That says nothing about max_concurrency, so a run only counts
        // as serial when every one of a few attempts is serial.
        $overlaps = [];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            @unlink(self::$log);
            @unlink(self::$state);
            Product::query()->delete();

            AiSeeder::seed(Product::class, 40, 'local'); // 4 batches of 10, max_concurrency 4

            $entries = $this->requestLog();

            $this->assertCount(4, $entries);

            $overlaps[] = $this->maxOverlap($entries);

            if (end($overlaps) >= 2) {
                break;
            }
        }

        $this->assertGreaterThanOrEqual(2, max($overlaps), 'All ' . count($overlaps) . ' attempts ran strictly one after another (measured overlaps: ' . implode(', ', $overlaps) . ').');
        $this->assertSame(40, Product::count());
    }

    public function test_a_rejected_key_fails_once_without_retrying_and_without_leaking_the_key (): void
    {
        $this->useServer('/auth', ['api_key' => 'wrong-key', 'requires_api_key' => TRUE]);

        try {
            AiSeeder::seed(Product::class, 5, 'local');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString('wrong-key', $e->getMessage());
        }

        $this->assertCount(1, $this->requestLog());
        $this->assertSame(0, Product::count());
    }

    public function test_a_provider_that_never_returns_json_fails_cleanly_and_writes_nothing (): void
    {
        $this->useServer('/garbage');

        try {
            AiSeeder::seed(Product::class, 5, 'local');
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('5 valid rows', $e->getMessage());
        }

        $this->assertCount(2, $this->requestLog()); // the batch and its one corrective retry
        $this->assertSame(0, Product::count());
    }

    /**
     * @param array<string, mixed> $driver
     */
    private function useServer (string $prefix = '', array $driver = []): void
    {
        config([
            'ai-seeder.default_driver' => 'local',
            'ai-seeder.drivers.local' => array_merge([
                'api_key' => NULL,
                'endpoint' => 'http://127.0.0.1:' . self::$port . $prefix . '/v1/chat/completions',
                'model' => 'fake-model',
                'batch_size' => 10,
                'max_concurrency' => 4,
                'json_mode' => FALSE,
                'timeout' => 10,
                'retries' => 3,
                'retry_delay' => 0,
                'requires_api_key' => FALSE,
            ], $driver),
        ]);
    }

    /**
     * @return array<int, array{path: string, start: float, end: float, status: int}>
     */
    private function requestLog (): array
    {
        if (! is_file(self::$log)) {
            return [];
        }

        $lines = file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(fn (string $line) => json_decode($line, TRUE), $lines);
    }

    /**
     * @param array<int, array{start: float, end: float}> $entries
     */
    private function maxOverlap (array $entries): int
    {
        $events = [];

        foreach ($entries as $entry) {
            $events[] = [$entry['start'], 1];
            $events[] = [$entry['end'], -1];
        }

        usort($events, fn (array $a, array $b) => $a <=> $b);

        $current = $max = 0;

        foreach ($events as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return $max;
    }
}
