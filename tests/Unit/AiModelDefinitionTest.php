<?php

namespace Vendor\AiSeeder\Tests\Unit;

use Faker\Generator;
use PHPUnit\Framework\TestCase;
use Vendor\AiSeeder\Ai;
use Vendor\AiSeeder\AiModelDefinition;
use Workbench\App\Seeding\ProductDefinition;

class AiModelDefinitionTest extends TestCase
{
    public function test_ai_fields_returns_only_ai_descriptors_keyed_by_column (): void
    {
        $fields = (new ProductDefinition)->aiFields();

        $this->assertSame(['name', 'description', 'category', 'price', 'in_stock', 'released_at'], array_keys($fields));
        $this->assertContainsOnlyInstancesOf(Ai::class, $fields);
    }

    public function test_local_fields_returns_everything_else_in_declaration_order (): void
    {
        $fields = (new ProductDefinition)->localFields();

        $this->assertSame(['slug', 'currency', 'note'], array_keys($fields));
        $this->assertInstanceOf(\Closure::class, $fields['slug']);
        $this->assertSame('EUR', $fields['currency']);
        $this->assertNull($fields['note']);
    }

    // Review Focus 4: falsy-but-valid literals are real values, not "missing".
    public function test_local_fields_keeps_falsy_literals (): void
    {
        $definition = new class extends AiModelDefinition
        {
            public function fields (): array
            {
                return ['zero' => 0, 'empty' => '', 'off' => FALSE, 'nothing' => NULL, 'name' => Ai::text()];
            }
        };

        $this->assertSame(['zero' => 0, 'empty' => '', 'off' => FALSE, 'nothing' => NULL], $definition->localFields());
        $this->assertSame(['name'], array_keys($definition->aiFields()));
    }

    public function test_faker_is_created_once (): void
    {
        $definition = new ProductDefinition;

        $this->assertInstanceOf(Generator::class, $definition->faker());
        $this->assertSame($definition->faker(), $definition->faker());
    }

    public function test_context_defaults_to_an_empty_string (): void
    {
        $definition = new class extends AiModelDefinition
        {
            public function fields (): array
            {
                return [];
            }
        };

        $this->assertSame('', $definition->context());
    }
}
