<?php

namespace Vendor\AiSeeder\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;
use Vendor\AiSeeder\Exceptions\GenerationFailed;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\Exceptions\MissingApiKey;
use Vendor\AiSeeder\Facades\AiSeeder;
use Vendor\AiSeeder\Tests\Support\DuplicateSlugDefinition;
use Vendor\AiSeeder\Tests\TestCase;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

class SeedingTest extends TestCase
{
    public function test_it_seeds_the_requested_number_of_rows_in_batches (): void
    {
        $this->fakeChat();

        $created = AiSeeder::seed(Product::class, 25);

        $this->assertCount(25, $created);
        $this->assertSame(25, Product::count());
        Http::assertSentCount(3); // batches of 10, 10 and 5

        $first = Product::query()->orderBy('id')->first();
        $this->assertSame('EUR', $first->currency);
        $this->assertNull($first->note);
        $this->assertIsBool($first->in_stock);
        $this->assertIsFloat($first->price);
        $this->assertMatchesRegularExpression('/^product-\d+-\d+$/', $first->slug);
    }

    public function test_generate_returns_rows_without_touching_the_database (): void
    {
        $this->fakeChat();

        $rows = AiSeeder::generate(Product::class, 3);

        $this->assertCount(3, $rows);
        $this->assertSame(0, Product::count());
    }

    public function test_it_uses_the_default_count_when_none_is_given (): void
    {
        $this->fakeChat();
        config(['ai-seeder.default_count' => 4]);

        $this->assertCount(4, AiSeeder::seed(Product::class));
    }

    public function test_it_accepts_a_short_model_name (): void
    {
        $this->fakeChat();

        $this->assertCount(2, AiSeeder::seed('product', 2));
    }

    public function test_it_fires_model_events_and_applies_casts (): void
    {
        $this->fakeChat();
        $created = 0;
        Product::created(function () use (&$created) {
            $created++;
        });

        AiSeeder::seed(Product::class, 2);

        $this->assertSame(2, $created);
    }

    public function test_the_driver_argument_overrides_the_default_driver (): void
    {
        $this->fakeChat();

        AiSeeder::seed(Product::class, 2, 'local');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'localhost:11434')
            && ! $request->hasHeader('Authorization')
            && ! isset($request['response_format']));
    }

    public function test_a_missing_required_api_key_fails_before_any_request (): void
    {
        $this->fakeChat();
        config(['ai-seeder.drivers.openai.api_key' => NULL]);

        try {
            AiSeeder::seed(Product::class, 2);
            $this->fail('Expected a MissingApiKey.');
        } catch (MissingApiKey) {
            Http::assertNothingSent();
            $this->assertSame(0, Product::count());
        }
    }

    public function test_a_failure_while_generating_leaves_no_rows (): void
    {
        // The batch of 5 is answered with prose, and so is its corrective retry.
        $this->fakeChat(fn (int $count) => $count === 5 ? 'Sorry, no JSON today.' : $this->aiRows($count, random_int(1000, 9000)));

        try {
            AiSeeder::seed(Product::class, 25);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed) {
            $this->assertSame(0, Product::count());
        }
    }

    // Review Focus 3: rows colliding with a unique column roll the whole model back with a clean error.
    public function test_a_unique_constraint_violation_rolls_everything_back_cleanly (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [Product::class => DuplicateSlugDefinition::class]]);

        try {
            AiSeeder::seed(Product::class, 3);
            $this->fail('Expected a GenerationFailed.');
        } catch (GenerationFailed $e) {
            $this->assertStringContainsString('nothing was written', $e->getMessage());
            $this->assertSame(0, Product::count());
        }
    }

    public function test_seeding_a_registered_class_that_is_not_an_eloquent_model_fails_before_any_request (): void
    {
        $this->fakeChat();
        config(['ai-seeder.models' => [stdClass::class => ProductDefinition::class]]);

        try {
            AiSeeder::seed(stdClass::class, 2);
            $this->fail('Expected an InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            $this->assertStringContainsString('is not an Eloquent model', $e->getMessage());
            Http::assertNothingSent();
        }
    }
}
