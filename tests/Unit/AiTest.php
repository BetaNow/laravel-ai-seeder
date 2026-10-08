<?php

namespace Vendor\AiSeeder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use Vendor\AiSeeder\Ai;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;

class AiTest extends TestCase
{
    public function test_text_accepts_non_empty_strings_and_trims_them (): void
    {
        $this->assertSame('Hello', Ai::text()->normalize('  Hello '));
        $this->assertRejected(Ai::text(), '');
        $this->assertRejected(Ai::text(), '   ');
        $this->assertRejected(Ai::text(), 12);
        $this->assertRejected(Ai::text(), NULL);
    }

    public function test_integer_accepts_ints_integral_floats_and_integer_strings (): void
    {
        $ai = Ai::integer('', 1, 10);

        $this->assertSame(5, $ai->normalize(5));
        $this->assertSame(5, $ai->normalize(5.0));
        $this->assertSame(5, $ai->normalize(' 5 '));
        $this->assertRejected($ai, 5.5);
        $this->assertRejected($ai, '5.5');
        $this->assertRejected($ai, 'abc');
        $this->assertRejected($ai, 0);
        $this->assertRejected($ai, 11);
        $this->assertRejected($ai, NAN);
    }

    public function test_float_accepts_numbers_and_numeric_strings_within_range (): void
    {
        $ai = Ai::float('', 5, 500);

        $this->assertSame(9.5, $ai->normalize(9.5));
        $this->assertSame(10.0, $ai->normalize(10));
        $this->assertSame(7.25, $ai->normalize('7.25'));
        $this->assertRejected($ai, 'abc');
        $this->assertRejected($ai, 4.99);
        $this->assertRejected($ai, 500.01);
        $this->assertRejected($ai, TRUE);
    }

    public function test_boolean_is_strict (): void
    {
        $this->assertTrue(Ai::boolean()->normalize(TRUE));
        $this->assertFalse(Ai::boolean()->normalize(FALSE));
        $this->assertRejected(Ai::boolean(), 'true');
        $this->assertRejected(Ai::boolean(), 1);
        $this->assertRejected(Ai::boolean(), NULL);
    }

    public function test_date_normalizes_to_y_m_d_and_rejects_overflow (): void
    {
        $this->assertSame('2026-02-28', Ai::date()->normalize('2026-02-28'));
        $this->assertSame('2026-02-28', Ai::date()->normalize('2026-02-28T10:30:00Z'));
        $this->assertSame('2026-02-28', Ai::date()->normalize('2026-02-28 10:30:00'));
        $this->assertRejected(Ai::date(), '2026-02-31');
        $this->assertRejected(Ai::date(), 'tomorrow');
        $this->assertRejected(Ai::date(), '');
        $this->assertRejected(Ai::date(), 20260228);
    }

    public function test_enum_requires_an_exact_option (): void
    {
        $ai = Ai::enum(['tents', 'boots']);

        $this->assertSame('tents', $ai->normalize('tents'));
        $this->assertRejected($ai, 'Tents');
        $this->assertRejected($ai, 'sandals');
        $this->assertRejected(Ai::enum([1, 2]), '1');
    }

    public function test_invalid_descriptors_fail_at_construction (): void
    {
        $this->expectException(InvalidDefinition::class);

        Ai::integer('', 5, 1);
    }

    public function test_an_empty_enum_is_invalid (): void
    {
        $this->expectException(InvalidDefinition::class);

        Ai::enum([]);
    }

    public function test_enum_options_must_be_strings_or_integers (): void
    {
        $this->expectException(InvalidDefinition::class);

        Ai::enum([['nested']]);
    }

    public function test_integer_rejects_out_of_range_floats (): void
    {
        $this->assertRejected(Ai::integer(), 1.8446744073709552E+19);
        $this->assertRejected(Ai::integer(), 1e20);
        $this->assertRejected(Ai::integer(), 2 ** 64);
        $this->assertRejected(Ai::integer(), 9.223372036854776E+18);
        $this->assertRejected(Ai::integer('', 0, 10), 1e20);
    }

    public function test_integer_rejects_out_of_range_numeric_strings (): void
    {
        $this->assertRejected(Ai::integer(), '99999999999999999999');
        $this->assertRejected(Ai::integer(), '9223372036854775808');
    }

    public function test_integer_accepts_normal_values (): void
    {
        $this->assertSame(-7, Ai::integer()->normalize('-7'));
        $this->assertSame(5, Ai::integer()->normalize(' 5 '));
        $this->assertSame(5, Ai::integer()->normalize(5.0));
    }

    public function test_float_rejects_infinite_strings (): void
    {
        $this->assertRejected(Ai::float(), '1e999');
        $this->assertRejected(Ai::float(), '-1e999');
        $this->assertRejected(Ai::float('', 5), '1e999');
        $this->assertRejected(Ai::float('', NULL, 500), '-1e999');
    }

    public function test_to_array_describes_the_field_for_the_prompt (): void
    {
        $this->assertSame(['type' => 'text', 'description' => 'a name'], Ai::text('a name')->toArray());
        $this->assertSame(['type' => 'integer', 'min' => 0, 'max' => 10], Ai::integer('', 0, 10)->toArray());
        $this->assertSame(['type' => 'enum', 'description' => 'kind', 'options' => ['a', 'b']], Ai::enum(['a', 'b'], 'kind')->toArray());
        $this->assertSame(['type' => 'date', 'format' => 'YYYY-MM-DD'], Ai::date()->toArray());
    }

    private function assertRejected (Ai $ai, mixed $value): void
    {
        try {
            $ai->normalize($value);
        } catch (UnexpectedValueException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected ' . json_encode($value) . ' to be rejected by Ai::' . $ai->type . '().');
    }
}
