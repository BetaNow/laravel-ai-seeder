<?php

namespace BetaNow\AiSeeder;

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
     * Returns the fields the LLM should generate.
     *
     * @return array<string, Ai> The AI-generated fields keyed by column name.
     */
    public function aiFields (): array
    {
        return array_filter($this->fields(), fn (mixed $field) => $field instanceof Ai);
    }

    /**
     * Returns the fields resolved locally: literals and Faker closures.
     *
     * @return array<string, mixed> The local fields keyed by column name.
     */
    public function localFields (): array
    {
        return array_filter($this->fields(), fn (mixed $field) => ! $field instanceof Ai);
    }

    /**
     * Returns a Faker instance for generating fake data.
     *
     * @return FakerGenerator An instance of the Faker generator.
     */
    public function faker (): FakerGenerator
    {
        if (! $this->faker) {
            $this->faker = Factory::create();
        }

        return $this->faker;
    }
}
