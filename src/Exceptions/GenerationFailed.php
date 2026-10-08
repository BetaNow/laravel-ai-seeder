<?php

namespace BetaNow\AiSeeder\Exceptions;

/**
 * Thrown when rows could not be generated or stored.
 *
 * @author BetaNow
 */
class GenerationFailed extends AiSeederException
{
    public static function transport (string $driver, string $reason): self
    {
        return new self("The [{$driver}] AI driver could not reach the provider: {$reason}");
    }

    public static function http (string $driver, int $status, string $reason): self
    {
        return new self("The [{$driver}] AI driver received HTTP {$status}: {$reason}");
    }

    public static function emptyContent (string $driver, ?string $refusal = NULL): self
    {
        $detail = $refusal !== NULL && $refusal !== '' ? " The provider said: {$refusal}" : '';

        return new self("The [{$driver}] AI driver returned no message content.{$detail}");
    }

    public static function shortfall (string $definition, int $expected, int $valid): self
    {
        return new self("Could not get {$expected} valid rows for {$definition}: only {$valid} were usable after one corrective retry.");
    }

    public static function insert (string $model, string $reason): self
    {
        return new self("Inserting the generated [{$model}] rows failed and nothing was written: {$reason}");
    }
}
