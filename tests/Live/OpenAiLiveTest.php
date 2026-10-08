<?php

namespace BetaNow\AiSeeder\Tests\Live;

use BetaNow\AiSeeder\Facades\AiSeeder;
use BetaNow\AiSeeder\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

/**
 * Calls the real OpenAI API and makes a paid request. It is excluded from the default run (phpunit.xml excludes the
 * "live" group), so it only runs when selected with --group live, and even then it is skipped unless both
 * AI_SEEDER_LIVE_TEST=1 and OPENAI_API_KEY are set.
 * Run it with: AI_SEEDER_LIVE_TEST=1 OPENAI_API_KEY=... vendor/bin/phpunit --group live
 */
#[Group('live')]
final class OpenAiLiveTest extends TestCase
{
    protected function setUp (): void
    {
        parent::setUp();

        if (getenv('AI_SEEDER_LIVE_TEST') !== '1' || ! getenv('OPENAI_API_KEY')) {
            $this->markTestSkipped('Set AI_SEEDER_LIVE_TEST=1 and OPENAI_API_KEY to run the live OpenAI test.');
        }

        config([
            'ai-seeder.default_driver' => 'openai',
            'ai-seeder.drivers.openai.api_key' => getenv('OPENAI_API_KEY'),
            'ai-seeder.drivers.openai.endpoint' => 'https://api.openai.com/v1/chat/completions',
            'ai-seeder.drivers.openai.model' => getenv('AI_SEEDER_OPENAI_MODEL') ?: 'gpt-4o-mini',
            'ai-seeder.drivers.openai.timeout' => 60,
            'ai-seeder.drivers.openai.retries' => 3,
            'ai-seeder.drivers.openai.retry_delay' => 500,
        ]);
    }

    public function test_openai_output_is_accepted_and_inserted (): void
    {
        $products = AiSeeder::seed(Product::class, 5);

        // Print what the model produced first, so a failing assertion below still shows it.
        fwrite(STDERR, PHP_EOL . $products->map(fn (Product $p) => "{$p->name} | {$p->category} | {$p->price} | {$p->description}")->implode(PHP_EOL) . PHP_EOL);

        $this->assertCount(5, $products);
        $this->assertSame(5, Product::count());

        foreach ($products as $product) {
            $this->assertContains($product->category, ProductDefinition::CATEGORIES);
            $this->assertGreaterThanOrEqual(5, $product->price);
            $this->assertLessThanOrEqual(500, $product->price);
        }
    }
}
