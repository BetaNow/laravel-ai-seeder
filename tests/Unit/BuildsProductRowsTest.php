<?php

namespace Vendor\AiSeeder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\AiSeeder\Tests\Support\BuildsProductRows;

class BuildsProductRowsTest extends TestCase
{
    use BuildsProductRows;

    public function test_ai_rows_with_zero_count_returns_empty_array (): void
    {
        $this->assertSame([], $this->aiRows(0));
    }

    public function test_ai_rows_with_negative_count_returns_empty_array (): void
    {
        $this->assertSame([], $this->aiRows(-1));
    }

    public function test_ai_rows_with_positive_count_returns_exact_count (): void
    {
        $this->assertCount(3, $this->aiRows(3));
    }

    public function test_ai_rows_with_start_parameter_uses_correct_numbers (): void
    {
        $rows = $this->aiRows(2, 5);

        $this->assertSame('Product 5', $rows[0]['name']);
        $this->assertSame('Product 6', $rows[1]['name']);
    }

    public function test_chat_content_with_empty_array_returns_json (): void
    {
        $this->assertSame('{"rows":[]}', $this->chatContent([]));
    }
}
