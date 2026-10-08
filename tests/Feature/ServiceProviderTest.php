<?php

namespace Vendor\AiSeeder\Tests\Feature;

use Vendor\AiSeeder\AiManager;
use Vendor\AiSeeder\Facades\AiSeeder;
use Vendor\AiSeeder\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_it_registers_the_manager_under_the_ai_seeder_alias (): void
    {
        $this->assertInstanceOf(AiManager::class, $this->app->make('ai-seeder'));
        $this->assertSame($this->app->make(AiManager::class), $this->app->make('ai-seeder'));
    }

    public function test_it_merges_the_package_configuration (): void
    {
        $this->assertSame('openai', config('ai-seeder.default_driver'));
        $this->assertArrayHasKey('local', config('ai-seeder.drivers'));
    }

    public function test_the_facade_resolves_the_manager (): void
    {
        $this->assertIsArray(AiSeeder::definitions());
    }
}
