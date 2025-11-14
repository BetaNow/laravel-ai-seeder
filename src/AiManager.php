<?php

namespace Vendor\AiSeeder;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Manages AI-related configurations and factories.
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
     */
    public function __construct (protected AiFactoryManager $factory, protected ConfigRepository $config)
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
}
