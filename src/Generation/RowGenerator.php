<?php

namespace Vendor\AiSeeder\Generation;

use Closure;
use Vendor\AiSeeder\Ai;
use Vendor\AiSeeder\AiModelDefinition;
use Vendor\AiSeeder\Contracts\AiDriver;
use Vendor\AiSeeder\Exceptions\GenerationFailed;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\Exceptions\UnparseableResponse;

/**
 * Generates rows for a definition: batches the work, asks the LLM for the AI fields, validates the answer
 * and merges in the literal and Faker fields.
 *
 * @author BetaNow
 */
final class RowGenerator
{
    public function __construct (
        private PromptBuilder $prompts = new PromptBuilder,
        private ResponseParser $parser = new ResponseParser,
    ) {
    }

    /**
     * Generates $count rows, in `fields()` declaration order.
     *
     * @param int $batchSize Rows requested per LLM call.
     * @param int $concurrency LLM calls in flight at once.
     *
     * @throws InvalidDefinition When the count, batch size or concurrency is not positive.
     * @throws GenerationFailed When a batch is still short after one corrective retry.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generate (AiModelDefinition $definition, int $count, AiDriver $driver, int $batchSize = 10, int $concurrency = 4): array
    {
        if ($count < 1) {
            throw InvalidDefinition::because("The number of rows to generate must be at least 1, got {$count}.");
        }

        if ($batchSize < 1 || $concurrency < 1) {
            throw InvalidDefinition::because("batch_size and max_concurrency must both be at least 1, got {$batchSize} and {$concurrency}.");
        }

        $aiFields = $definition->aiFields();
        $local = $definition->localFields();
        $order = array_keys($definition->fields());

        if ($aiFields === []) {
            return array_map(fn () => $this->assemble($definition, $local, $order, []), range(1, $count));
        }

        $rows = [];

        foreach (array_chunk($this->batchSizes($count, $batchSize), $concurrency, TRUE) as $group) {
            $requests = [];

            foreach ($group as $index => $size) {
                $requests[$index] = [
                    'system' => $this->prompts->system(),
                    'user' => $this->prompts->user($definition, $size),
                ];
            }

            $responses = $driver->completeMany($requests);

            foreach ($group as $index => $size) {
                foreach ($this->collect($definition, $aiFields, $size, $responses[$index] ?? '', $driver) as $aiRow) {
                    $rows[] = $this->assemble($definition, $local, $order, $aiRow);
                }
            }
        }

        return $rows;
    }

    /**
     * Splits $count into batch sizes, e.g. 25 by 10 becomes [10, 10, 5].
     *
     * @return array<int, int>
     */
    public function batchSizes (int $count, int $batchSize): array
    {
        $sizes = array_fill(0, intdiv($count, $batchSize), $batchSize);

        if ($count % $batchSize > 0) {
            $sizes[] = $count % $batchSize;
        }

        return $sizes;
    }

    /**
     * Returns exactly $size valid AI rows for one batch, asking once more if the first reply fell short.
     *
     * @param array<string, Ai> $aiFields
     * @return array<int, array<string, mixed>>
     */
    private function collect (AiModelDefinition $definition, array $aiFields, int $size, string $content, AiDriver $driver): array
    {
        $valid = $this->usableRows($content, $aiFields);

        if (count($valid) < $size) {
            $retry = $driver->complete(
                $this->prompts->system(),
                $this->prompts->user($definition, $size - count($valid), TRUE)
            );

            $valid = [...$valid, ...$this->usableRows($retry, $aiFields)];
        }

        if (count($valid) < $size) {
            throw GenerationFailed::shortfall($definition::class, $size, count($valid));
        }

        return array_slice($valid, 0, $size);
    }

    /**
     * @param array<string, Ai> $aiFields
     * @return array<int, array<string, mixed>>
     */
    private function usableRows (string $content, array $aiFields): array
    {
        try {
            return $this->parser->parse($content, $aiFields)['rows'];
        } catch (UnparseableResponse) {
            return [];
        }
    }

    /**
     * Resolves the local fields on top of the AI values and restores declaration order.
     *
     * @param array<string, mixed> $local
     * @param array<int, string> $order
     * @param array<string, mixed> $aiRow
     * @return array<string, mixed>
     */
    private function assemble (AiModelDefinition $definition, array $local, array $order, array $aiRow): array
    {
        $row = $aiRow;

        foreach ($local as $column => $value) {
            $row[$column] = $value instanceof Closure ? $value($definition->faker(), $row) : $value;
        }

        $ordered = [];

        foreach ($order as $column) {
            $ordered[$column] = $row[$column];
        }

        return $ordered;
    }
}
