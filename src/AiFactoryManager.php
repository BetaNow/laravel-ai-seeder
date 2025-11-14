<?php

namespace Vendor\AiSeeder;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

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

        return (array)$this->config->get("ai-seeder.drivers.{$driver}", []);
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
}
