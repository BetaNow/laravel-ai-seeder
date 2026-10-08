<?php

namespace BetaNow\AiSeeder\Generation;

use BetaNow\AiSeeder\Ai;
use BetaNow\AiSeeder\AiModelDefinition;
use BetaNow\AiSeeder\Exceptions\InvalidDefinition;
use JsonException;

/**
 * Builds the system and user messages sent to the LLM.
 *
 * @author BetaNow
 */
final class PromptBuilder
{
    /**
     * Returns the system message.
     */
    public function system (): string
    {
        return 'You generate realistic, varied seed data for a database. '
            . 'Reply with one JSON object of the form {"rows": [...]} and nothing else: no prose, no markdown, no code fences. '
            . 'Every row must contain exactly the requested field names, with values of the requested types, and rows must differ from each other.';
    }

    /**
     * Returns the user message: a JSON document describing what to generate.
     *
     * @param bool $strict Adds a reminder, used for the corrective retry.
     */
    public function user (AiModelDefinition $definition, int $count, bool $strict = FALSE): string
    {
        $payload = [
            'count' => $count,
            'context' => $definition->context(),
            'fields' => array_map(fn (Ai $field) => $field->toArray(), $definition->aiFields()),
        ];

        if ($strict) {
            $payload['reminder'] = "Your previous answer was unusable. Return exactly {$count} valid rows as JSON, strictly following the field types.";
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw InvalidDefinition::because('The definition context or field hints must be valid UTF-8 text: ' . $e->getMessage());
        }
    }
}
