<?php

namespace Vendor\AiSeeder\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Vendor\AiSeeder\AiManager;
use Vendor\AiSeeder\Exceptions\AiSeederException;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\SeedRunner;

/**
 * Seeds registered Eloquent models with AI-generated rows.
 *
 * @author BetaNow
 */
class SeedCommand extends Command
{
    protected $signature = 'ai-seeder:seed
        {model? : Model class or short name registered in ai-seeder.models}
        {--count= : Number of rows to create per model}
        {--driver= : Driver to use instead of the default one}
        {--dry-run : Print the generated rows without inserting them}';

    protected $description = 'Seed Eloquent models with AI-generated data';

    /**
     * Runs the command.
     */
    public function handle (AiManager $ai, SeedRunner $runner): int
    {
        try {
            $count = $this->requestedCount();
            $driver = $this->option('driver') ?: NULL;
            $dryRun = (bool)$this->option('dry-run');
            $models = $this->targets($ai);

            // Fail fast: validate every target before generating or writing anything.
            foreach ($models as $model) {
                $ai->makeDefinition($model);

                if (! $dryRun) {
                    $runner->assertEloquentModel($model);
                }
            }

            foreach ($models as $model) {
                $dryRun
                    ? $this->preview($ai, $model, $count, $driver)
                    : $this->seed($ai, $model, $count, $driver);
            }
        } catch (AiSeederException $e) {
            // The message may hold user or provider text such as "<fg=zzz>", which the console formatter would reject.
            $this->components->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function requestedCount (): ?int
    {
        $raw = $this->option('count');

        if ($raw === NULL || $raw === '') {
            return NULL;
        }

        // Leading zeros are accepted ('007' is 7); a value PHP cannot hold exactly (it would saturate) is rejected.
        $digits = ltrim((string)$raw, '0');

        if (! ctype_digit((string)$raw) || $digits === '' || (string)(int)$digits !== $digits) {
            throw InvalidDefinition::because("--count must be a positive integer, got [{$raw}].");
        }

        return (int)$digits;
    }

    /**
     * @return array<int, string>
     */
    private function targets (AiManager $ai): array
    {
        if ($this->argument('model') !== NULL) {
            return [$ai->resolveModel((string)$this->argument('model'))];
        }

        if ($ai->models() === []) {
            throw InvalidDefinition::because('No models are registered in ai-seeder.models.');
        }

        return $ai->models();
    }

    private function seed (AiManager $ai, string $model, ?int $count, ?string $driver): void
    {
        $created = $ai->seed($model, $count, $driver);

        $this->components->info(OutputFormatter::escape(sprintf('Seeded %d %s rows.', $created->count(), class_basename($model))));
    }

    private function preview (AiManager $ai, string $model, ?int $count, ?string $driver): void
    {
        $rows = $ai->generate($model, $count, $driver);

        $this->components->info(OutputFormatter::escape(sprintf('%s: %d generated rows (dry run, nothing inserted).', class_basename($model), count($rows))));
        $this->table(
            array_map(fn (int|string $column) => OutputFormatter::escape((string)$column), array_keys($rows[0])),
            array_map(fn (array $row) => array_map(fn (mixed $value) => OutputFormatter::escape($this->display($value)), $row), $rows)
        );
    }

    private function display (mixed $value): string
    {
        return match (TRUE) {
            $value === NULL => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string)$value,
            default => (string)json_encode($value),
        };
    }
}
