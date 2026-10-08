<?php

namespace Workbench\App\Seeding;

use BetaNow\AiSeeder\Ai;
use BetaNow\AiSeeder\AiModelDefinition;
use Faker\Generator;
use Illuminate\Support\Str;

/**
 * Demo definition: an outdoor-gear shop. AI writes the product text, Faker/literals fill the rest.
 *
 * @author BetaNow
 */
class ProductDefinition extends AiModelDefinition
{
    public const CATEGORIES = ['tents', 'boots', 'packs'];

    public function context (): string
    {
        return 'an online shop selling outdoor gear';
    }

    public function fields (): array
    {
        return [
            'name' => Ai::text('product name'),
            'description' => Ai::text('one-sentence marketing blurb'),
            'category' => Ai::enum(self::CATEGORIES, 'product category'),
            'price' => Ai::float('price in EUR', 5, 500),
            'in_stock' => Ai::boolean('whether the product is in stock'),
            'released_at' => Ai::date('release date'),
            'slug' => fn (Generator $faker, array $row) => Str::slug($row['name']) . '-' . $faker->unique()->numberBetween(1000, 99999),
            'currency' => 'EUR',
            'note' => NULL,
        ];
    }
}
