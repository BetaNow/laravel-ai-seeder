<?php

namespace Vendor\AiSeeder;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;

/**
 * Manages AI-related configurations and factories, and is the entry point for generating and seeding rows.
 *
 * @author BetaNow
 */
class AiManager
{
    /**
     * Creates a new instance of the AiManager class.
     *
     * @param AiFactoryManager $factory The factory manager instance.
     * @param ConfigRepository $config The configuration repository instance.
     * @param SeedRunner $runner The runner that generates and stores rows.
     */
    public function __construct (protected AiFactoryManager $factory, protected ConfigRepository $config, protected SeedRunner $runner)
    {
    }

    /**
     * Returns the factory manager instance.
     *
     * @return AiFactoryManager The factory manager instance.
     */
    public function factory (): AiFactoryManager
    {
        return $this->factory;
    }

    /**
     * Returns the configuration for the specified driver.
     *
     * @param string|null $driver The name of the driver.
     * @return array<string, mixed> The configuration for the specified driver.
     */
    public function driver (?string $driver = NULL): array
    {
        return $this->factory->getDriverConfig($driver);
    }

    /**
     * Returns the list of model definitions.
     *
     * @return array<class-string, class-string> The list of model definitions.
     */
    public function definitions (): array
    {
        return (array)$this->config->get('ai-seeder.models', []);
    }

    /**
     * Retrieves the definition for the specified model.
     *
     * @param string $model The name of the model to retrieve the definition for.
     * @return string|null The definition of the model if it exists, or null if it does not.
     */
    public function definitionFor (string $model): ?string
    {
        return $this->definitions()[$model] ?? NULL;
    }

    /**
     * Returns the registered models, in configuration order.
     *
     * @return array<int, class-string>
     */
    public function models (): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Resolves a full class name or a (case-insensitive) short name to a registered model.
     *
     * @throws InvalidDefinition When nothing, or more than one model, matches.
     */
    public function resolveModel (string $name): string
    {
        $name = ltrim($name, '\\');

        if (array_key_exists($name, $this->definitions())) {
            return $name;
        }

        $matches = array_values(array_filter(
            $this->models(),
            fn (string $model) => strcasecmp(class_basename($model), $name) === 0
        ));

        return match (count($matches)) {
            0 => throw InvalidDefinition::unknownModel($name, $this->models()),
            1 => $matches[0],
            default => throw InvalidDefinition::ambiguousModel($name, $matches),
        };
    }

    /**
     * Instantiates the definition registered for a model.
     *
     * @throws InvalidDefinition When the model is not registered, or its definition is not an AiModelDefinition or
     *                           cannot be instantiated without arguments.
     */
    public function makeDefinition (string $model): AiModelDefinition
    {
        $definitions = $this->definitions();

        if (! array_key_exists($model, $definitions)) {
            throw InvalidDefinition::unknownModel($model, $this->models());
        }

        // Read the raw config value: a misconfigured entry (array, object, NULL ...) must be reported, not coerced.
        $class = $definitions[$model];

        if (! is_string($class) || ! is_subclass_of($class, AiModelDefinition::class)) {
            throw InvalidDefinition::notADefinition(is_string($class) ? $class : get_debug_type($class));
        }

        // `new $class` would otherwise throw a raw Error (abstract, private constructor) or ArgumentCountError.
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw InvalidDefinition::because("[{$class}] cannot be instantiated: it is abstract or has a non-public constructor.");
        }

        if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw InvalidDefinition::because("[{$class}] cannot be instantiated without arguments: its constructor has required parameters.");
        }

        return new $class;
    }

    /**
     * Generates rows for a model without writing them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function generate (string $model, ?int $count = NULL, ?string $driver = NULL): array
    {
        $model = $this->resolveModel($model);

        return $this->runner->generate($this->makeDefinition($model), $count, $driver);
    }

    /**
     * Generates and stores rows for a model in a single transaction.
     *
     * @return Collection<int, Model> The created models.
     */
    public function seed (string $model, ?int $count = NULL, ?string $driver = NULL): Collection
    {
        $model = $this->resolveModel($model);

        return $this->runner->seed($model, $this->makeDefinition($model), $count, $driver);
    }
}
