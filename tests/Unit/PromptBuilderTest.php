<?php

namespace BetaNow\AiSeeder\Tests\Unit;

use BetaNow\AiSeeder\Ai;
use BetaNow\AiSeeder\AiModelDefinition;
use BetaNow\AiSeeder\Exceptions\InvalidDefinition;
use BetaNow\AiSeeder\Generation\PromptBuilder;
use PHPUnit\Framework\TestCase;
use Workbench\App\Seeding\ProductDefinition;

class PromptBuilderTest extends TestCase
{
    public function test_the_system_prompt_demands_a_bare_json_rows_object (): void
    {
        $system = (new PromptBuilder)->system();

        $this->assertStringContainsString('{"rows": [...]}', $system);
        $this->assertStringContainsString('no markdown', $system);
    }

    public function test_the_user_prompt_describes_count_context_and_ai_fields_only (): void
    {
        $spec = json_decode((new PromptBuilder)->user(new ProductDefinition, 7), TRUE);

        $this->assertSame(7, $spec['count']);
        $this->assertSame('an online shop selling outdoor gear', $spec['context']);
        $this->assertSame(['name', 'description', 'category', 'price', 'in_stock', 'released_at'], array_keys($spec['fields']));
        $this->assertSame(['type' => 'float', 'description' => 'price in EUR', 'min' => 5, 'max' => 500], $spec['fields']['price']);
        $this->assertSame(['tents', 'boots', 'packs'], $spec['fields']['category']['options']);
        $this->assertArrayNotHasKey('slug', $spec['fields']);
        $this->assertArrayNotHasKey('reminder', $spec);
    }

    public function test_strict_mode_adds_a_reminder (): void
    {
        $spec = json_decode((new PromptBuilder)->user(new ProductDefinition, 2, TRUE), TRUE);

        $this->assertStringContainsString('exactly 2 valid rows', $spec['reminder']);
    }

    public function test_hostile_text_is_encoded_not_concatenated (): void
    {
        $context = "He said \"hi\"\nsecond line \\ with ünïcödé 日本語 and {\"rows\": []}";
        $definition = $this->definitionWithContext($context, Ai::text("a \"quoted\"\nhint"));

        $spec = json_decode((new PromptBuilder)->user($definition, 1), TRUE, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($context, $spec['context']);
        $this->assertSame("a \"quoted\"\nhint", $spec['fields']['title']['description']);
    }

    public function test_invalid_utf8_in_the_definition_is_reported_cleanly (): void
    {
        $definition = $this->definitionWithContext("broken \xB1\x31 text", Ai::text());

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('valid UTF-8');

        (new PromptBuilder)->user($definition, 1);
    }

    private function definitionWithContext (string $context, Ai $field): AiModelDefinition
    {
        return new class($context, $field) extends AiModelDefinition
        {
            public function __construct (private string $context, private Ai $field)
            {
            }

            public function context (): string
            {
                return $this->context;
            }

            public function fields (): array
            {
                return ['title' => $this->field];
            }
        };
    }
}
