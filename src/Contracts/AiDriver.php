<?php

namespace Vendor\AiSeeder\Contracts;

/**
 * Talks to an LLM provider.
 *
 * @author BetaNow
 */
interface AiDriver
{
    /**
     * Sends one system/user prompt pair and returns the raw message content of the reply.
     */
    public function complete (string $system, string $user): string;

    /**
     * Sends several prompts concurrently and returns the raw replies under the same keys.
     *
     * @param array<array-key, array{system: string, user: string}> $requests
     * @return array<array-key, string>
     */
    public function completeMany (array $requests): array;
}
