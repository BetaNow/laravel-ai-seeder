<?php

namespace Vendor\AiSeeder\Tests;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Vendor\AiSeeder\AiSeederServiceProvider;
use Vendor\AiSeeder\Facades\AiSeeder;
use Vendor\AiSeeder\Tests\Support\BuildsProductRows;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

abstract class TestCase extends Orchestra
{
    use BuildsProductRows;

    private int $fakeSequence = 0;

    protected function getPackageProviders ($app): array
    {
        return [AiSeederServiceProvider::class];
    }

    protected function getPackageAliases ($app): array
    {
        return ['AiSeeder' => AiSeeder::class];
    }

    protected function defineEnvironment ($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => TRUE,
        ]);

        $app['config']->set('ai-seeder.default_driver', 'openai');
        $app['config']->set('ai-seeder.models', [Product::class => ProductDefinition::class]);
        $app['config']->set('ai-seeder.drivers.openai', [
            'api_key' => 'test-key',
            'endpoint' => 'https://api.test/v1/chat/completions',
            'model' => 'test-model',
            'batch_size' => 10,
            'max_concurrency' => 4,
            'json_mode' => TRUE,
            'timeout' => 5,
            'retries' => 2,
            'retry_delay' => 0,
            'requires_api_key' => TRUE,
        ]);
        // Pinned in full as well, so exported AI_SEEDER_LOCAL_* variables cannot change what the tests expect.
        $app['config']->set('ai-seeder.drivers.local', [
            'api_key' => NULL,
            'endpoint' => 'http://localhost:11434/v1/chat/completions',
            'model' => 'test-local-model',
            'batch_size' => 10,
            'max_concurrency' => 4,
            'json_mode' => FALSE,
            'timeout' => 5,
            'retries' => 2,
            'retry_delay' => 0,
            'requires_api_key' => FALSE,
        ]);
    }

    protected function defineDatabaseMigrations (): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/workbench/database/migrations');
    }

    /**
     * Fakes the chat-completions endpoint and forbids any unfaked request.
     *
     * $rows receives (int $count, array $spec) and returns the rows to answer with, or a raw string to use as the
     * message content. Without it, every request is answered with $count valid, never-repeating Product rows.
     */
    protected function fakeChat (?Closure $rows = NULL): void
    {
        Http::preventStrayRequests();

        Http::fake(['*' => function (Request $request) use ($rows) {
            $spec = json_decode($request['messages'][1]['content'], TRUE);
            $count = (int)$spec['count'];

            if ($rows === NULL) {
                $generated = $this->aiRows($count, $this->fakeSequence + 1);
                $this->fakeSequence += $count;
            } else {
                $generated = $rows($count, $spec);
            }

            return Http::response($this->chatBody(is_string($generated) ? $generated : $this->chatContent($generated)));
        }]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatBody (string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }
}
