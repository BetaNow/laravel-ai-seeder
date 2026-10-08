<?php

namespace Vendor\AiSeeder;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Vendor\AiSeeder\Contracts\AiDriver;
use Vendor\AiSeeder\Drivers\ChatCompletionsDriver;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;

/**
 * Handles the management of AI factory drivers and their configurations.
 *
 * @author BetaNow
 */
class AiFactoryManager
{
    /**
     * Creates a new instance of the AiFactoryManager class.
     *
     * @param ConfigRepository $config The configuration repository instance.
     */
    public function __construct (protected ConfigRepository $config)
    {
    }

    /**
     * Returns the default AI factory driver.
     *
     * @return string The default AI factory driver.
     */
    public function getDefaultDriver (): string
    {
        return (string)$this->config->get('ai-seeder.default_driver', 'openai');
    }

    /**
     * Retrieves the configuration for the specified AI factory driver.
     *
     * @param string|null $driver The name of the AI factory driver.
     * @return array<string, mixed> The configuration for the specified AI factory driver.
     */
    public function getDriverConfig (?string $driver = NULL): array
    {
        $driver = $driver ?: $this->getDefaultDriver();

        // Not config('ai-seeder.drivers.<name>'): a dotted name such as "openai.model" would resolve a nested value.
        $config = $this->getDrivers()[$driver] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * Retrieves all available AI factory drivers.
     *
     * @return array<string, array<string, mixed>> The list of available AI factory drivers.
     */
    public function getDrivers (): array
    {
        return (array)$this->config->get('ai-seeder.drivers', []);
    }

    /**
     * Builds the driver for the given name (or the default driver).
     *
     * @param string|null $driver The name of the AI factory driver.
     * @return AiDriver The driver instance.
     */
    public function make (?string $driver = NULL): AiDriver
    {
        $name = $driver ?: $this->getDefaultDriver();
        $config = $this->getDriverConfig($name);

        if ($config === []) {
            throw InvalidDefinition::unknownDriver($name, array_keys($this->getDrivers()));
        }

        return new ChatCompletionsDriver($name, $config);
    }
}
