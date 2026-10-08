<?php

namespace Vendor\AiSeeder\Tests\Support;

use Workbench\App\Seeding\ProductDefinition;

trait BuildsProductRows
{
    /**
     * A valid LLM-generated row for ProductDefinition (the AI fields only).
     *
     * @return array<string, mixed>
     */
    protected function aiRow (int $n): array
    {
        return [
            'name' => "Product {$n}",
            'description' => "Description of product {$n}.",
            'category' => ProductDefinition::CATEGORIES[$n % 3],
            'price' => 10 + $n,
            'in_stock' => $n % 2 === 0,
            'released_at' => '2025-03-01',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function aiRows (int $count, int $start = 1): array
    {
        return array_map(fn (int $n) => $this->aiRow($n), $count > 0 ? range($start, $start + $count - 1) : []);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function chatContent (array $rows): string
    {
        return json_encode(['rows' => $rows], JSON_THROW_ON_ERROR);
    }
}
