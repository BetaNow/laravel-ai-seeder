<?php

namespace Vendor\AiSeeder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\AiSeeder\Exceptions\UnparseableResponse;
use Vendor\AiSeeder\Generation\ResponseParser;
use Vendor\AiSeeder\Tests\Support\BuildsProductRows;
use Workbench\App\Seeding\ProductDefinition;

class ResponseParserTest extends TestCase
{
    use BuildsProductRows;

    public function test_it_parses_a_rows_object (): void
    {
        $result = $this->parse($this->chatContent($this->aiRows(2)));

        $this->assertCount(2, $result['rows']);
        $this->assertSame(0, $result['dropped']);
        $this->assertSame('Product 1', $result['rows'][0]['name']);
        $this->assertSame(11.0, $result['rows'][0]['price']);
    }

    public function test_it_parses_a_bare_array (): void
    {
        $this->assertCount(2, $this->parse(json_encode($this->aiRows(2)))['rows']);
    }

    public function test_it_parses_a_fenced_block_with_or_without_a_language (): void
    {
        $json = $this->chatContent($this->aiRows(1));

        $this->assertCount(1, $this->parse("```json\n{$json}\n```")['rows']);
        $this->assertCount(1, $this->parse("```\n{$json}\n```")['rows']);
    }

    public function test_it_tolerates_a_utf8_byte_order_mark (): void
    {
        $this->assertCount(1, $this->parse("\xEF\xBB\xBF" . $this->chatContent($this->aiRows(1)))['rows']);
    }

    public function test_it_drops_invalid_rows_and_counts_them (): void
    {
        $rows = $this->aiRows(4);
        $rows[1]['price'] = 9999;          // out of range
        unset($rows[2]['name']);           // missing key
        $rows[3]['category'] = 'sandals';  // not an option

        $result = $this->parse($this->chatContent($rows));

        $this->assertCount(1, $result['rows']);
        $this->assertSame(3, $result['dropped']);
    }

    public function test_it_drops_non_array_rows (): void
    {
        $result = $this->parse(json_encode(['rows' => ['text', 5, NULL]]));

        $this->assertSame([], $result['rows']);
        $this->assertSame(3, $result['dropped']);
    }

    public function test_it_keeps_only_the_requested_keys (): void
    {
        $row = $this->aiRow(1) + ['slug' => 'injected', 'extra' => 'x'];

        $result = $this->parse($this->chatContent([$row]));

        $this->assertSame(['name', 'description', 'category', 'price', 'in_stock', 'released_at'], array_keys($result['rows'][0]));
    }

    public function test_an_empty_rows_array_is_valid_but_empty (): void
    {
        $this->assertSame(['rows' => [], 'dropped' => 0], $this->parse('{"rows": []}'));
    }

    public function test_it_rejects_replies_that_are_not_the_expected_structure (): void
    {
        foreach (['Sorry, I cannot do that.', '{"data": []}', '"just a string"', '42', '{"rows": "x"}', '', '{"rows": [}'] as $content) {
            try {
                $this->parse($content);
            } catch (UnparseableResponse) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->fail('Expected an UnparseableResponse for: ' . $content);
        }
    }

    public function test_fenced_blocks_with_large_whitespace_runs_complete_quickly (): void
    {
        // Regression: the old regex /^```[a-zA-Z]*\s*(.*?)\s*```$/s with nested quantifiers
        // experienced quadratic backtrack time when whitespace runs sit between content tokens.
        // Whitespace at fence boundaries is handled greedily (fast), but inside content it causes
        // the lazy (.*?) to re-scan the entire run on each backtrack step.
        //
        // The old pattern: 60,000 newlines between "rows": and [...] took 0.66s
        //                 60,000 newlines, no closing fence took 3.97s
        // The new code: same inputs take ~0.0 seconds.

        $row = ['name' => 'Test', 'description' => 'A test product', 'category' => 'tents', 'price' => 10.0, 'in_stock' => true, 'released_at' => '2025-01-01'];

        // Test 1: 60,000 newlines INSIDE JSON (between "rows": and [...]), closed fence (must parse)
        $json1 = '{"rows":' . str_repeat("\n", 60000) . json_encode([$row]) . '}';
        $start = hrtime(true);
        $result = $this->parse("```json\n{$json1}\n```");
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(1.0, $elapsed, "Parsing with 60k newlines inside JSON took {$elapsed}s (should be < 1.0s)");
        $this->assertCount(1, $result['rows']);

        // Test 2: 60,000 spaces INSIDE JSON (between "rows": and [...]), closed fence (must parse)
        $json2 = '{"rows":' . str_repeat(" ", 60000) . json_encode([$row]) . '}';
        $start = hrtime(true);
        $result = $this->parse("```json\n{$json2}\n```");
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(1.0, $elapsed, "Parsing with 60k spaces inside JSON took {$elapsed}s (should be < 1.0s)");
        $this->assertCount(1, $result['rows']);

        // Test 3: 60,000 newlines INSIDE JSON, UNCLOSED fence (must throw, old regex stalls longer)
        $start = hrtime(true);
        try {
            $this->parse("```json\n{$json1}");
        } catch (UnparseableResponse) {
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertLessThan(1.0, $elapsed, "Parsing unclosed fence with 60k newlines took {$elapsed}s (should be < 1.0s)");
        }

        // Test 4: 60,000 spaces INSIDE JSON, UNCLOSED fence (must throw, old regex stalls longer)
        $start = hrtime(true);
        try {
            $this->parse("```json\n{$json2}");
        } catch (UnparseableResponse) {
            $elapsed = (hrtime(true) - $start) / 1e9;
            $this->assertLessThan(1.0, $elapsed, "Parsing unclosed fence with 60k spaces took {$elapsed}s (should be < 1.0s)");
        }
    }

    public function test_fenced_blocks_handle_crlf_line_endings_and_embedded_backticks (): void
    {
        // Test CRLF line endings
        $json = json_encode(['rows' => [['name' => 'Test', 'description' => 'A test product', 'category' => 'tents', 'price' => 10.0, 'in_stock' => true, 'released_at' => '2025-01-01']]]);
        $result = $this->parse("```\r\n{$json}\r\n```");
        $this->assertCount(1, $result['rows']);

        // Test bare array in fence
        $array = json_encode([['name' => 'Test', 'description' => 'A test product', 'category' => 'tents', 'price' => 10.0, 'in_stock' => true, 'released_at' => '2025-01-01']]);
        $result = $this->parse("```\n{$array}\n```");
        $this->assertCount(1, $result['rows']);

        // Test JSON string containing three backticks inside a fenced block
        $jsonWithBackticks = json_encode(['rows' => [['name' => 'Test', 'description' => 'Has ``` inside', 'category' => 'tents', 'price' => 10.0, 'in_stock' => true, 'released_at' => '2025-01-01']]]);
        $result = $this->parse("```json\n{$jsonWithBackticks}\n```");
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Has ``` inside', $result['rows'][0]['description']);
    }

    public function test_unclosed_or_truncated_fences_throw_unparseable_response (): void
    {
        $json = json_encode(['rows' => []]);

        // Starts with fence but does not end with fence
        try {
            $this->parse("```json\n{$json}");
            $this->fail('Expected an UnparseableResponse for unclosed fence');
        } catch (UnparseableResponse) {
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            $this->fail('Expected UnparseableResponse, got ' . get_class($e) . ': ' . $e->getMessage());
        }

        // Ends with fence but does not start with fence
        try {
            $this->parse("{$json}\n```");
            $this->fail('Expected an UnparseableResponse for fence without opening');
        } catch (UnparseableResponse) {
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            $this->fail('Expected UnparseableResponse, got ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function parse (string $content): array
    {
        return (new ResponseParser)->parse($content, (new ProductDefinition)->aiFields());
    }
}
