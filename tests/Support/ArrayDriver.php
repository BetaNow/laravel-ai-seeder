<?php

namespace BetaNow\AiSeeder\Tests\Support;

use BetaNow\AiSeeder\Contracts\AiDriver;
use RuntimeException;

/**
 * Answers with queued replies and records every prompt it receives.
 */
final class ArrayDriver implements AiDriver
{
    /** @var array<int, array{system: string, user: string}> */
    public array $calls = [];

    public int $manyCalls = 0;

    /** @var array<int, string> */
    private array $responses;

    /**
     * @param array<int, string> $responses
     */
    public function __construct (array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function complete (string $system, string $user): string
    {
        $this->calls[] = ['system' => $system, 'user' => $user];

        return array_shift($this->responses) ?? throw new RuntimeException('ArrayDriver ran out of queued responses.');
    }

    public function completeMany (array $requests): array
    {
        $this->manyCalls++;
        $results = [];

        foreach ($requests as $key => $request) {
            $results[$key] = $this->complete($request['system'], $request['user']);
        }

        return $results;
    }
}
