<?php

namespace BetaNow\AiSeeder;

use BetaNow\AiSeeder\Exceptions\GenerationFailed;
use BetaNow\AiSeeder\Exceptions\InvalidDefinition;
use BetaNow\AiSeeder\Generation\RowGenerator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Generates rows with the configured driver and stores them atomically.
 *
 * @author BetaNow
 */
final class SeedRunner
{
    /**
     * Creates a new instance of the SeedRunner class.
     *
     * @param RowGenerator $generator The row generator.
     * @param AiFactoryManager $factory The driver factory.
     * @param ConfigRepository $config The configuration repository instance.
     */
    public function __construct (
        private RowGenerator $generator,
        private AiFactoryManager $factory,
        private ConfigRepository $config,
    ) {
    }

    /**
     * Generates rows without writing anything.
     *
     * @param int|null $count Rows to generate; ai-seeder.default_count when NULL.
     * @param string|null $driver The driver name; the default driver when NULL.
     * @return array<int, array<string, mixed>>
     */
    public function generate (AiModelDefinition $definition, ?int $count = NULL, ?string $driver = NULL): array
    {
        $name = $driver ?: $this->factory->getDefaultDriver();
        $settings = $this->factory->getDriverConfig($name);

        return $this->generator->generate(
            $definition,
            $count ?? (int)$this->config->get('ai-seeder.default_count', 10),
            $this->factory->make($name),
            (int)($settings['batch_size'] ?? 10),
            (int)($settings['max_concurrency'] ?? 4),
        );
    }

    /**
     * Ensures a class can be written to, so callers can validate every target before generating anything.
     *
     * @throws InvalidDefinition When the class is not an Eloquent model.
     */
    public function assertEloquentModel (string $model): void
    {
        if (! is_subclass_of($model, Model::class)) {
            throw InvalidDefinition::because("[{$model}] is not an Eloquent model.");
        }
    }

    /**
     * Generates every row first, then inserts them in a single transaction.
     *
     * @param class-string<Model> $model
     *
     * @throws GenerationFailed When generating or inserting fails; nothing is written in that case.
     *
     * @return Collection<int, Model> The created models.
     */
    public function seed (string $model, AiModelDefinition $definition, ?int $count = NULL, ?string $driver = NULL): Collection
    {
        $this->assertEloquentModel($model);

        $rows = $this->generate($definition, $count, $driver);

        try {
            return (new $model)->getConnection()->transaction(
                fn () => collect($rows)->map(fn (array $row) => $model::query()->forceCreate($row))
            );
        } catch (QueryException $e) {
            throw GenerationFailed::insert($model, $e->getPrevious()?->getMessage() ?? $e->getMessage());
        }
    }
}
