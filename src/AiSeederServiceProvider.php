<?php

namespace Vendor\AiSeeder;

use Illuminate\Support\ServiceProvider;

/**
 * AiSeederServiceProvider is a service provider that manages the registration and bootstrapping of services related to
 * AI Seeder functionality in a Laravel application.
 *
 * @author BetaNow
 */
class AiSeederServiceProvider extends ServiceProvider
{
    /**
     * Registers the necessary bindings and configuration for the AI Seeder functionality.
     *
     * @return void
     */
    public function register (): void
    {
        $this->mergeConfigFrom($this->configFile(), 'ai-seeder');

        $this->app->singleton(AiFactoryManager::class, function ($app) {
            return new AiFactoryManager($app['config']);
        });

        $this->app->singleton(AiManager::class, function ($app) {
            return new AiManager(
                $app->make(AiFactoryManager::class),
                $app['config']
            );
        });

        $this->app->alias(AiManager::class, 'ai-seeder');
    }

    /**
     * Boots the AI Seeder functionality by publishing the configuration file when running in the console.
     *
     * @return void
     */
    public function boot (): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configFile() => $this->publishPath(),
            ], 'ai-seeder-config');
        }
    }

    /**
     * Retrieves the file path of the configuration file.
     *
     * @return string The path to the configuration file.
     */
    protected function configFile (): string
    {
        return __DIR__ . '/../config/ai-seeder.php';
    }

    /**
     * Determines and retrieves the path where the configuration file should be published.
     *
     * @return string The path to the configuration file intended for publishing.
     */
    protected function publishPath (): string
    {
        if (method_exists($this->app, 'configPath')) {
            return $this->app->configPath('ai-seeder.php');
        }

        return $this->app->basePath('config/ai-seeder.php');
    }
}
