<?php

namespace Vendor\AiSeeder\Exceptions;

/**
 * Thrown for an invalid definition, configuration value or argument.
 *
 * @author BetaNow
 */
final class InvalidDefinition extends AiSeederException
{
    public static function because (string $message): self
    {
        return new self($message);
    }

    /**
     * @param array<int, string> $known
     */
    public static function unknownModel (string $model, array $known): self
    {
        $list = $known === [] ? 'none' : implode(', ', $known);

        return new self("No definition is registered for [{$model}] in ai-seeder.models. Registered models: {$list}.");
    }

    /**
     * @param array<int, string> $known
     */
    public static function unknownDriver (string $driver, array $known): self
    {
        $list = $known === [] ? 'none' : implode(', ', $known);

        return new self("The AI driver [{$driver}] is not configured in ai-seeder.drivers. Configured drivers: {$list}.");
    }

    public static function notADefinition (string $class): self
    {
        return new self("[{$class}] must be a class extending Vendor\\AiSeeder\\AiModelDefinition.");
    }

    /**
     * @param array<int, string> $matches
     */
    public static function ambiguousModel (string $name, array $matches): self
    {
        return new self("[{$name}] matches several registered models (" . implode(', ', $matches) . '). Use the full class name.');
    }
}
