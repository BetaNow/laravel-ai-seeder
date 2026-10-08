<?php

namespace BetaNow\AiSeeder\Tests\Feature;

use BetaNow\AiSeeder\AiManager;
use BetaNow\AiSeeder\Exceptions\InvalidDefinition;
use BetaNow\AiSeeder\Tests\Support\AbstractProductDefinition;
use BetaNow\AiSeeder\Tests\Support\ConstructorArgumentDefinition;
use BetaNow\AiSeeder\Tests\TestCase;
use stdClass;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

class AiManagerTest extends TestCase
{
    public function test_models_lists_registered_models_in_config_order (): void
    {
        config(['ai-seeder.models' => ['App\\B' => ProductDefinition::class, 'App\\A' => ProductDefinition::class]]);

        $this->assertSame(['App\\B', 'App\\A'], $this->manager()->models());
    }

    public function test_resolve_model_accepts_the_full_name_or_a_case_insensitive_short_name (): void
    {
        $this->assertSame(Product::class, $this->manager()->resolveModel(Product::class));
        $this->assertSame(Product::class, $this->manager()->resolveModel('Product'));
        $this->assertSame(Product::class, $this->manager()->resolveModel('product'));
    }

    public function test_resolve_model_rejects_unknown_names (): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('No definition is registered for [Ghost]');

        $this->manager()->resolveModel('Ghost');
    }

    public function test_resolve_model_rejects_ambiguous_short_names (): void
    {
        config(['ai-seeder.models' => ['A\\Product' => ProductDefinition::class, 'B\\Product' => ProductDefinition::class]]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('matches several registered models');

        $this->manager()->resolveModel('Product');
    }

    public function test_make_definition_instantiates_the_registered_definition (): void
    {
        $this->assertInstanceOf(ProductDefinition::class, $this->manager()->makeDefinition(Product::class));
    }

    public function test_make_definition_rejects_a_class_that_is_not_a_definition (): void
    {
        config(['ai-seeder.models' => [Product::class => stdClass::class]]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('must be a class extending');

        $this->manager()->makeDefinition(Product::class);
    }

    public function test_resolve_model_trims_a_leading_backslash (): void
    {
        $this->assertSame(Product::class, $this->manager()->resolveModel('\\' . Product::class));
    }

    public function test_resolve_model_prefers_an_exact_key_over_a_short_name_collision (): void
    {
        config(['ai-seeder.models' => ['Product' => ProductDefinition::class, 'App\\Product' => ProductDefinition::class]]);

        $this->assertSame('Product', $this->manager()->resolveModel('Product'));
    }

    public function test_resolve_model_returns_a_registered_key_whose_definition_is_null (): void
    {
        config(['ai-seeder.models' => [Product::class => NULL]]);

        $this->assertSame(Product::class, $this->manager()->resolveModel(Product::class));
    }

    public function test_make_definition_rejects_an_array_value (): void
    {
        $this->expectNotADefinition(['x'], 'array');
    }

    public function test_make_definition_rejects_an_object_instance (): void
    {
        $this->expectNotADefinition(new ProductDefinition, ProductDefinition::class);
    }

    public function test_make_definition_rejects_a_null_value_as_an_invalid_definition_not_an_unknown_model (): void
    {
        $this->expectNotADefinition(NULL, 'null');
    }

    public function test_make_definition_rejects_an_int_value (): void
    {
        $this->expectNotADefinition(42, 'int');
    }

    public function test_make_definition_rejects_a_string_naming_a_class_that_does_not_exist (): void
    {
        $this->expectNotADefinition('App\\Missing\\Definition', 'App\\Missing\\Definition');
    }

    public function test_make_definition_rejects_an_abstract_definition_cleanly (): void
    {
        config(['ai-seeder.models' => [Product::class => AbstractProductDefinition::class]]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('[' . AbstractProductDefinition::class . '] cannot be instantiated');

        $this->manager()->makeDefinition(Product::class);
    }

    public function test_make_definition_rejects_a_definition_whose_constructor_needs_arguments_cleanly (): void
    {
        config(['ai-seeder.models' => [Product::class => ConstructorArgumentDefinition::class]]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('[' . ConstructorArgumentDefinition::class . '] cannot be instantiated without arguments');

        $this->manager()->makeDefinition(Product::class);
    }

    public function test_make_definition_accepts_a_constructor_whose_arguments_are_all_optional (): void
    {
        $definition = new class extends ProductDefinition
        {
            public function __construct (public string $currency = 'EUR')
            {
            }
        };
        config(['ai-seeder.models' => [Product::class => $definition::class]]);

        $this->assertInstanceOf($definition::class, $this->manager()->makeDefinition(Product::class));
    }

    /**
     * Registers $value as Product's definition and expects makeDefinition() to reject it, naming $description.
     */
    private function expectNotADefinition (mixed $value, string $description): void
    {
        config(['ai-seeder.models' => [Product::class => $value]]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage("[{$description}] must be a class extending");

        $this->manager()->makeDefinition(Product::class);
    }

    private function manager (): AiManager
    {
        return $this->app->make(AiManager::class);
    }
}
