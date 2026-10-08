<?php

namespace Vendor\AiSeeder\Exceptions;

/**
 * Thrown when a provider reply is not the JSON structure the prompt asked for.
 *
 * @author BetaNow
 */
final class UnparseableResponse extends GenerationFailed
{
    public static function because (string $reason): self
    {
        return new self("The provider reply could not be parsed: {$reason}");
    }
}
