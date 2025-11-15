<?php

namespace Vendor\AiSeeder;

use Faker\Factory;
use Faker\Generator as FakerGenerator;

/**
 * Represents the abstract definition of an AI model, providing a common structure for model implementations.
 *
 * @author BetaNow
 */
abstract class AiModelDefinition
{
    /**
     * The Faker generator instance.
     */
    protected ?FakerGenerator $faker = NULL;

    /**
     * Returns the fields of the AI model.
     *
     * @return array<string, mixed> The fields of the AI model.
     */
    abstract public function fields (): array;

    /**
     * Returns the context of the AI model.
     *
     * @return string The context of the AI model.
     */
    public function context (): string
    {
        return '';
    }

    /**
     * Returns a Faker instance for generating fake data.
     *
     * @return FakerGenerator An instance of the Faker generator.
     */
    public function faker (): FakerGenerator
    {
        if (!$this->faker) {
            $this->faker = Factory::create();
        }

        return $this->faker;
    }
}
