<?php

namespace Vendor\AiSeeder\Tests\Unit;

use Faker\Generator;
use PHPUnit\Framework\TestCase;
use Vendor\AiSeeder\AiModelDefinition;
use Vendor\AiSeeder\Exceptions\GenerationFailed;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\Generation\RowGenerator;
use Vendor\AiSeeder\Tests\Support\ArrayDriver;
use Vendor\AiSeeder\Tests\Support\BuildsProductRows;
use Workbench\App\Seeding\ProductDefinition;

class RowGeneratorTest extends TestCase
{
    use BuildsProductRows;

    public function test_it_merges_ai_and_local_fields_in_declaration_order (): void
    {
        $driver = new ArrayDriver([$this->chatContent($this->aiRows(3))]);

        $rows = (new RowGenerator)->generate(new ProductDefinition, 3, $driver);

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['name', 'description', 'category', 'price', 'in_stock', 'released_at', 'slug', 'currency', 'note'],
            array_keys($rows[0])
        );
        $this->assertSame('Product 1', $rows[0]['name']);
        $this->assertMatchesRegularExpression('/^product-1-\d+$/', $rows[0]['slug']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertNull($rows[0]['note']);
        $this->assertCount(1, $driver->calls);
    }

    public function test_batch_sizes_splits_the_count (): void
    {
        $generator = new RowGenerator;

        $this->assertSame([10, 10, 5], $generator->batchSizes(25, 10));
        $this->assertSame([10], $generator->batchSizes(10, 10));
        $this->assertSame([3], $generator->batchSizes(3, 10));
        $this->assertSame([1, 1, 1], $generator->batchSizes(3, 1));
    }

    public function test_it_sends_batches_in_groups_of_the_concurrency_limit (): void
    {
        $driver = new ArrayDriver([
            $this->chatContent($this->aiRows(10, 1)),
            $this->chatContent($this->aiRows(10, 11)),
            $this->chatContent($this->aiRows(5, 21)),
        ]);

        $rows = (new RowGenerator)->generate(new ProductDefinition, 25, $driver, 10, 2);

        $this->assertCount(25, $rows);
        $this->assertSame(2, $driver->manyCalls); // [batch 0, batch 1] then [batch 2]
        $this->assertSame('Product 25', $rows[24]['name']);
    }

    public function test_it_truncates_surplus_rows (): void
    {
        $driver = new ArrayDriver([$this->chatContent($this->aiRows(5))]);

        $this->assertCount(3, (new RowGenerator)->generate(new ProductDefinition, 3, $driver));
    }

    public function test_it_tops_up_invalid_rows_with_one_strict_request (): void
    {
        $rows = $this->aiRows(3);
        $rows[1]['price'] = 9999; // out of range, dropped
        $driver = new ArrayDriver([$this->chatContent($rows), $this->chatContent($this->aiRows(1, 10))]);

        $result = (new RowGenerator)->generate(new ProductDefinition, 3, $driver);

        $this->assertCount(3, $result);
        $this->assertCount(2, $driver->calls);

        $topUp = json_decode($driver->calls[1]['user'], TRUE);
        $this->assertSame(1, $topUp['count']);
        $this->assertArrayHasKey('reminder', $topUp);
    }

    public function test_an_unparseable_reply_triggers_a_corrective_request_for_the_whole_batch (): void
    {
        $driver = new ArrayDriver(['Sorry, here is some prose.', $this->chatContent($this->aiRows(3))]);

        $result = (new RowGenerator)->generate(new ProductDefinition, 3, $driver);

        $this->assertCount(3, $result);
        $this->assertSame(3, json_decode($driver->calls[1]['user'], TRUE)['count']);
    }

    public function test_it_fails_when_the_retry_is_still_short (): void
    {
        $driver = new ArrayDriver([$this->chatContent($this->aiRows(1)), 'still not json']);

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessageMatches('/3 valid rows.*only 1/');

        (new RowGenerator)->generate(new ProductDefinition, 3, $driver);
    }

    public function test_a_definition_without_ai_fields_never_calls_the_driver (): void
    {
        $definition = new class extends AiModelDefinition
        {
            public function fields (): array
            {
                return [
                    'a' => 1,
                    'b' => fn (Generator $faker, array $row) => $row['a'] + 1,
                    'c' => fn (Generator $faker, array $row) => $row['b'] + 1,
                ];
            }
        };
        $driver = new ArrayDriver([]);

        $rows = (new RowGenerator)->generate($definition, 4, $driver);

        $this->assertCount(4, $rows);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $rows[0]);
        $this->assertSame([], $driver->calls);
        $this->assertSame(0, $driver->manyCalls);
    }

    // Review Focus 4: falsy-but-valid literals survive assembly.
    public function test_falsy_literals_are_kept_in_generated_rows (): void
    {
        $definition = new class extends AiModelDefinition
        {
            public function fields (): array
            {
                return ['zero' => 0, 'empty' => '', 'off' => FALSE, 'nothing' => NULL];
            }
        };

        $rows = (new RowGenerator)->generate($definition, 1, new ArrayDriver([]));

        $this->assertSame([['zero' => 0, 'empty' => '', 'off' => FALSE, 'nothing' => NULL]], $rows);
    }

    // Review Focus 2: the count is validated before anything is sent.
    public function test_a_non_positive_count_is_rejected (): void
    {
        foreach ([0, -3] as $count) {
            $driver = new ArrayDriver([]);

            try {
                (new RowGenerator)->generate(new ProductDefinition, $count, $driver);
                $this->fail("Expected an InvalidDefinition for count {$count}.");
            } catch (InvalidDefinition $e) {
                $this->assertStringContainsString('at least 1', $e->getMessage());
                $this->assertSame([], $driver->calls);
            }
        }
    }

    // Review Focus 5: a zero batch size or concurrency must not divide by zero or loop forever.
    public function test_non_positive_batch_size_or_concurrency_is_rejected (): void
    {
        foreach ([[0, 4], [10, 0], [-1, 4], [10, -2]] as [$batchSize, $concurrency]) {
            try {
                (new RowGenerator)->generate(new ProductDefinition, 5, new ArrayDriver([]), $batchSize, $concurrency);
                $this->fail("Expected an InvalidDefinition for batch_size {$batchSize} / max_concurrency {$concurrency}.");
            } catch (InvalidDefinition $e) {
                $this->assertStringContainsString('batch_size and max_concurrency', $e->getMessage());
            }
        }
    }
}
