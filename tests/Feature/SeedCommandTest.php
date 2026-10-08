<?php

namespace BetaNow\AiSeeder\Tests\Feature;

use BetaNow\AiSeeder\Tests\Support\AbstractProductDefinition;
use BetaNow\AiSeeder\Tests\Support\ConstructorArgumentDefinition;
use BetaNow\AiSeeder\Tests\Support\SecondProduct;
use BetaNow\AiSeeder\Tests\Support\UsdProductDefinition;
use BetaNow\AiSeeder\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use stdClass;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

class SeedCommandTest extends TestCase
{
    public function test_it_seeds_a_single_model (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 5])
            ->expectsOutputToContain('Seeded 5 Product rows.')
            ->assertExitCode(0);

        $this->assertSame(5, Product::count());
    }

    public function test_it_seeds_every_configured_model_with_the_default_count_when_no_model_is_given (): void
    {
        $this->fakeChat();
        config(['ai-seeder.default_count' => 3]);

        $this->artisan('ai-seeder:seed')
            ->expectsOutputToContain('Seeded 3 Product rows.')
            ->assertExitCode(0);

        $this->assertSame(3, Product::count());
    }

    public function test_dry_run_prints_the_rows_and_inserts_nothing (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 2, '--dry-run' => TRUE])
            ->expectsOutputToContain('dry run')
            ->expectsOutputToContain('Product 1')
            ->assertExitCode(0);

        $this->assertSame(0, Product::count());
    }

    public function test_the_driver_option_overrides_the_default_driver (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 2, '--driver' => 'local'])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'localhost:11434'));
    }

    // Review Focus 2: bad counts are rejected before anything is sent or written.
    public function test_invalid_counts_are_rejected (): void
    {
        $this->fakeChat();

        foreach (['0', '-3', 'abc', '2.5'] as $count) {
            $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => $count])
                ->expectsOutputToContain('--count must be a positive integer')
                ->assertExitCode(1);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_counts_that_do_not_fit_an_integer_are_rejected_instead_of_crashing (): void
    {
        $this->fakeChat();

        foreach (['99999999999999999999', '9223372036854775808'] as $count) {
            $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => $count])
                ->expectsOutputToContain('--count must be a positive integer')
                ->assertExitCode(1);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_a_count_with_leading_zeros_is_still_accepted (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => '007'])
            ->expectsOutputToContain('Seeded 7 Product rows.')
            ->assertExitCode(0);

        $this->assertSame(7, Product::count());
    }

    public function test_an_empty_or_zero_model_argument_is_not_treated_as_no_model (): void
    {
        $this->fakeChat();

        foreach (['', '0'] as $model) {
            $this->artisan('ai-seeder:seed', ['model' => $model, '--count' => 2])
                ->expectsOutputToContain('No definition is registered for [' . $model . ']')
                ->assertExitCode(1);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_an_unknown_model_is_reported_cleanly (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Ghost'])
            ->expectsOutputToContain('No definition is registered for [Ghost]')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_no_models_are_registered (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => []]);

        $this->artisan('ai-seeder:seed')
            ->expectsOutputToContain('No models are registered')
            ->assertExitCode(1);
    }

    public function test_a_bad_entry_fails_the_whole_run_before_anything_is_written (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [
            Product::class => ProductDefinition::class,
            'App\\Models\\Ghost' => stdClass::class,
        ]]);

        $this->artisan('ai-seeder:seed')
            ->expectsOutputToContain('must be a class extending')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_a_registered_class_that_is_not_an_eloquent_model_fails_the_whole_run_before_anything_is_written (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [
            Product::class => ProductDefinition::class,
            'App\\Models\\Ghost' => ProductDefinition::class,
        ]]);

        $this->artisan('ai-seeder:seed', ['--count' => 2])
            ->expectsOutputToContain('is not an Eloquent model')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_a_dry_run_does_not_require_an_eloquent_model_because_nothing_is_written (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [
            Product::class => ProductDefinition::class,
            'App\\Models\\Ghost' => ProductDefinition::class,
        ]]);

        $this->artisan('ai-seeder:seed', ['--count' => 2, '--dry-run' => TRUE])
            ->expectsOutputToContain('Ghost: 2 generated rows (dry run')
            ->assertExitCode(0);

        $this->assertSame(0, Product::count());
    }

    public function test_a_generation_failure_is_reported_without_a_stack_trace (): void
    {
        $this->fakeChat(fn () => 'Sorry, no JSON today.');

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 3])
            ->expectsOutputToContain('valid rows')
            ->assertExitCode(1);

        $this->assertSame(0, Product::count());
    }

    public function test_it_seeds_every_configured_model_in_config_order (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [
            Product::class => ProductDefinition::class,
            SecondProduct::class => UsdProductDefinition::class,
        ]]);

        $exitCode = Artisan::call('ai-seeder:seed', ['--count' => 2]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);

        $first = strpos($output, 'Seeded 2 Product rows.');
        $second = strpos($output, 'Seeded 2 SecondProduct rows.');

        $this->assertNotFalse($first, $output);
        $this->assertNotFalse($second, $output);
        $this->assertLessThan($second, $first, "The first model must be reported first:\n" . $output);

        // The first model's rows were written first (ids 1-2, EUR), the second model's after them (ids 3-4, USD).
        $this->assertSame([1 => 'EUR', 2 => 'EUR', 3 => 'USD', 4 => 'USD'], Product::query()->orderBy('id')->pluck('currency', 'id')->all());
        $this->assertSame([1 => 'Product 1', 2 => 'Product 2', 3 => 'Product 3', 4 => 'Product 4'], Product::query()->orderBy('id')->pluck('name', 'id')->all());

        // One request per model, sent in config order.
        $contexts = Http::recorded()
            ->map(fn (array $pair) => json_decode($pair[0]['messages'][1]['content'], TRUE)['context'])
            ->all();

        $this->assertSame([(new ProductDefinition)->context(), (new UsdProductDefinition)->context()], $contexts);
    }

    public function test_an_unknown_driver_option_is_reported_cleanly (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--driver' => 'nope'])
            ->expectsOutputToContain('nope')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_a_dotted_driver_name_is_an_unknown_driver_and_never_echoes_a_config_value (): void
    {
        $this->fakeChat();

        $exitCode = Artisan::call('ai-seeder:seed', ['model' => 'Product', '--driver' => 'openai.api_key']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('not configured', $output);
        $this->assertStringContainsString('openai.api_key', $output);
        $this->assertStringNotContainsString('test-key', $output);
        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_a_definition_that_cannot_be_instantiated_fails_the_whole_run_before_anything_is_sent (): void
    {
        $this->fakeChat();

        $cases = [
            AbstractProductDefinition::class => 'cannot be instantiated',
            ConstructorArgumentDefinition::class => 'cannot be instantiated without arguments',
        ];

        foreach ($cases as $definition => $message) {
            config(['ai-seeder.models' => [
                Product::class => ProductDefinition::class,
                SecondProduct::class => $definition,
            ]]);

            $this->artisan('ai-seeder:seed', ['--count' => 2])
                ->expectsOutputToContain($message)
                ->assertExitCode(1);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_console_formatting_tags_in_a_model_name_or_count_are_printed_literally (): void
    {
        $this->fakeChat();

        $this->artisan('ai-seeder:seed', ['model' => '<fg=zzz>x'])
            ->expectsOutputToContain('<fg=zzz>x')
            ->assertExitCode(1);

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => '<fg=zzz>x'])
            ->expectsOutputToContain('<fg=zzz>x')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, Product::count());
    }

    public function test_console_formatting_tags_in_a_provider_error_are_printed_literally (): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bad <fg=zzz>request</> from the provider']], 400)]);

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 2])
            ->expectsOutputToContain('Bad <fg=zzz>request</> from the provider')
            ->assertExitCode(1);

        $this->assertSame(0, Product::count());
    }

    public function test_console_formatting_tags_in_generated_values_are_printed_literally_by_a_dry_run (): void
    {
        $this->fakeChat(fn (int $count) => array_map(
            fn (array $row) => ['name' => '<fg=zzz>Tent</>'] + $row,
            $this->aiRows($count)
        ));

        $this->artisan('ai-seeder:seed', ['model' => 'Product', '--count' => 2, '--dry-run' => TRUE])
            ->expectsOutputToContain('<fg=zzz>Tent</>')
            ->assertExitCode(0);

        $this->assertSame(0, Product::count());
    }
}
