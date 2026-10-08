<?php

namespace Vendor\AiSeeder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vendor\AiSeeder\Exceptions\AiSeederException;
use Vendor\AiSeeder\Exceptions\GenerationFailed;
use Vendor\AiSeeder\Exceptions\InvalidDefinition;
use Vendor\AiSeeder\Exceptions\MissingApiKey;
use Vendor\AiSeeder\Exceptions\UnparseableResponse;

class ExceptionsTest extends TestCase
{
    public function test_every_exception_extends_the_package_base_exception (): void
    {
        $this->assertInstanceOf(AiSeederException::class, MissingApiKey::forDriver('openai'));
        $this->assertInstanceOf(AiSeederException::class, GenerationFailed::shortfall('X', 3, 1));
        $this->assertInstanceOf(AiSeederException::class, InvalidDefinition::because('nope'));
        $this->assertInstanceOf(GenerationFailed::class, UnparseableResponse::because('bad json'));
    }

    public function test_missing_api_key_names_the_driver_and_the_env_variable (): void
    {
        $message = MissingApiKey::forDriver('openai')->getMessage();

        $this->assertStringContainsString('[openai]', $message);
        $this->assertStringContainsString('AI_SEEDER_OPENAI_API_KEY', $message);
    }

    public function test_messages_carry_the_useful_details (): void
    {
        $this->assertStringContainsString('429', GenerationFailed::http('openai', 429, 'slow down')->getMessage());
        $this->assertStringContainsString('3 valid rows', GenerationFailed::shortfall('ProductDefinition', 3, 1)->getMessage());
        $this->assertStringContainsString('nothing was written', GenerationFailed::insert('Product', 'duplicate')->getMessage());
        $this->assertStringContainsString('No definition is registered for [Ghost]', InvalidDefinition::unknownModel('Ghost', ['Product'])->getMessage());
        $this->assertStringContainsString('[nope]', InvalidDefinition::unknownDriver('nope', ['openai', 'local'])->getMessage());
    }
}
