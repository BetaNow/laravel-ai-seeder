<?php

namespace Vendor\AiSeeder;

use DateTimeImmutable;
use UnexpectedValueException;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;

/**
 * Describes a field whose value the LLM should generate, and validates what comes back.
 *
 * @author BetaNow
 */
final class Ai
{
    /**
     * @param array<int, string|int> $options
     */
    private function __construct (
        public readonly string $type,
        public readonly string $hint = '',
        public readonly array $options = [],
        public readonly int|float|null $min = NULL,
        public readonly int|float|null $max = NULL,
    ) {
        if ($min !== NULL && $max !== NULL && $min > $max) {
            throw InvalidDefinition::because("Ai::{$type}() was given a min ({$min}) greater than its max ({$max}).");
        }
    }

    public static function text (string $hint = ''): self
    {
        return new self('text', $hint);
    }

    public static function integer (string $hint = '', ?int $min = NULL, ?int $max = NULL): self
    {
        return new self('integer', $hint, [], $min, $max);
    }

    public static function float (string $hint = '', int|float|null $min = NULL, int|float|null $max = NULL): self
    {
        return new self('float', $hint, [], $min, $max);
    }

    public static function boolean (string $hint = ''): self
    {
        return new self('boolean', $hint);
    }

    public static function date (string $hint = ''): self
    {
        return new self('date', $hint);
    }

    /**
     * @param array<int, string|int> $options
     */
    public static function enum (array $options, string $hint = ''): self
    {
        $invalid = array_filter($options, fn (mixed $option) => ! is_string($option) && ! is_int($option));

        if ($options === [] || $invalid !== []) {
            throw InvalidDefinition::because('Ai::enum() needs a non-empty list of string or integer options.');
        }

        return new self('enum', $hint, array_values($options));
    }

    /**
     * Returns the cleaned value, or throws when the LLM's value does not satisfy this field.
     *
     * @throws UnexpectedValueException
     */
    public function normalize (mixed $value): mixed
    {
        return match ($this->type) {
            'text' => $this->normalizeText($value),
            'integer' => $this->withinRange($this->normalizeInteger($value)),
            'float' => $this->withinRange($this->normalizeFloat($value)),
            'boolean' => is_bool($value) ? $value : throw $this->reject($value),
            'date' => $this->normalizeDate($value),
            'enum' => in_array($value, $this->options, TRUE) ? $value : throw $this->reject($value),
        };
    }

    /**
     * Describes the field to the LLM.
     *
     * @return array<string, mixed>
     */
    public function toArray (): array
    {
        $field = array_filter([
            'type' => $this->type,
            'description' => $this->hint,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
        ], fn (mixed $value) => $value !== NULL && $value !== '' && $value !== []);

        if ($this->type === 'date') {
            $field['format'] = 'YYYY-MM-DD';
        }

        return $field;
    }

    private function normalizeText (mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : throw $this->reject($value);
    }

    private function normalizeInteger (mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            if ($value >= -9.2233720368547758E+18 && $value < 9.2233720368547758E+18) {
                return (int)$value;
            }

            throw $this->reject($value);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $trimmed = trim($value);
            $int = (int)$trimmed;

            if ((string)$int === $trimmed) {
                return $int;
            }

            throw $this->reject($value);
        }

        throw $this->reject($value);
    }

    private function normalizeFloat (mixed $value): float
    {
        if ((is_int($value) || is_float($value)) && is_finite((float)$value)) {
            return (float)$value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $result = (float)trim($value);

            if (is_finite($result)) {
                return $result;
            }

            throw $this->reject($value);
        }

        throw $this->reject($value);
    }

    private function normalizeDate (mixed $value): string
    {
        if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:$|[T ])/', trim($value), $matches) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);

            if ($date !== FALSE && $date->format('Y-m-d') === $matches[1]) {
                return $matches[1];
            }
        }

        throw $this->reject($value);
    }

    private function withinRange (int|float $value): int|float
    {
        if (($this->min !== NULL && $value < $this->min) || ($this->max !== NULL && $value > $this->max)) {
            throw $this->reject($value);
        }

        return $value;
    }

    private function reject (mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException("Expected a valid {$this->type}, got " . get_debug_type($value) . '.');
    }
}
