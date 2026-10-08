<?php

namespace BetaNow\AiSeeder\Exceptions;

/**
 * Thrown before any HTTP call when a driver needs an API key and none is configured.
 *
 * @author BetaNow
 */
final class MissingApiKey extends AiSeederException
{
    public static function forDriver (string $driver): self
    {
        $env = 'AI_SEEDER_' . strtoupper($driver) . '_API_KEY';

        return new self("The [{$driver}] AI driver needs an API key. Set ai-seeder.drivers.{$driver}.api_key (env {$env}).");
    }
}
