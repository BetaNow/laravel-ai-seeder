<?php

namespace Vendor\AiSeeder\Tests\Feature;

use Vendor\AiSeeder\AiFactoryManager;
use Vendor\AiSeeder\Drivers\ChatCompletionsDriver;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\Tests\TestCase;

class AiFactoryManagerTest extends TestCase
{
    public function test_it_builds_a_driver_for_every_configured_driver (): void
    {
        $factory = $this->app->make(AiFactoryManager::class);

        $this->assertInstanceOf(ChatCompletionsDriver::class, $factory->make('openai'));
        $this->assertInstanceOf(ChatCompletionsDriver::class, $factory->make('local'));
        $this->assertInstanceOf(ChatCompletionsDriver::class, $factory->make());
    }

    public function test_it_rejects_an_unconfigured_driver (): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('[nope]');

        $this->app->make(AiFactoryManager::class)->make('nope');
    }

    public function test_a_dotted_name_is_an_unknown_driver_instead_of_a_nested_config_lookup (): void
    {
        $factory = $this->app->make(AiFactoryManager::class);

        foreach (['openai.model', 'openai.api_key', 'openai.json_mode', 'openai.retries'] as $name) {
            try {
                $factory->make($name);
                $this->fail("Expected an InvalidDefinition for [{$name}].");
            } catch (InvalidDefinition $e) {
                $this->assertStringContainsString("[{$name}]", $e->getMessage());
                $this->assertStringContainsString('not configured', $e->getMessage());
                $this->assertStringNotContainsString('test-key', $e->getMessage());
            }

            $this->assertSame([], $factory->getDriverConfig($name));
        }
    }

    public function test_the_default_driver_config_is_returned_when_no_name_is_given (): void
    {
        $factory = $this->app->make(AiFactoryManager::class);

        $this->assertSame('test-model', $factory->getDriverConfig()['model']);
        $this->assertSame('test-model', $factory->getDriverConfig(NULL)['model']);
        $this->assertSame('test-local-model', $factory->getDriverConfig('local')['model']);
    }

    public function test_the_packaged_config_defaults_are_sane (): void
    {
        // Reads the packaged defaults, so it depends on the environment not overriding those AI_SEEDER_* variables.
        $this->assertSame('http://localhost:11434/v1/chat/completions', config('ai-seeder.drivers.local.endpoint'));
        $this->assertFalse(config('ai-seeder.drivers.local.requires_api_key'));
        $this->assertTrue(config('ai-seeder.drivers.openai.requires_api_key'));
        $this->assertSame(10, config('ai-seeder.default_count'));
    }
}
