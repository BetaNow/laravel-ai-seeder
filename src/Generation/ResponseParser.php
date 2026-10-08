<?php

namespace BetaNow\AiSeeder\Generation;

use BetaNow\AiSeeder\Ai;
use BetaNow\AiSeeder\Exceptions\UnparseableResponse;
use JsonException;
use UnexpectedValueException;

/**
 * Turns an LLM reply into validated rows.
 *
 * @author BetaNow
 */
final class ResponseParser
{
    /**
     * Parses a reply (raw JSON, fenced JSON, or a bare array) and validates each row against the AI fields.
     *
     * @param array<string, Ai> $fields The AI fields every row must satisfy.
     *
     * @throws UnparseableResponse When the reply is not JSON or has no rows array.
     *
     * @return array{rows: array<int, array<string, mixed>>, dropped: int}
     */
    public function parse (string $content, array $fields): array
    {
        $data = $this->decode($content);
        $candidates = array_is_list($data) ? $data : ($data['rows'] ?? NULL);

        if (! is_array($candidates)) {
            throw UnparseableResponse::because('the JSON has no "rows" array.');
        }

        $rows = [];
        $dropped = 0;

        foreach ($candidates as $candidate) {
            $row = $this->validRow($candidate, $fields);

            if ($row === NULL) {
                $dropped++;

                continue;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'dropped' => $dropped];
    }

    /**
     * @return array<mixed>
     */
    private function decode (string $content): array
    {
        $content = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $content));

        // Strip fence markers (```...```) using linear-time string operations.
        // Avoid nested/lazy quantifiers which cause super-linear backtrack time on untrusted input.
        if (str_starts_with($content, '```') && str_ends_with($content, '```') && strlen($content) >= 6) {
            $content = trim((string)preg_replace('/^[a-zA-Z]*+/', '', substr($content, 3, -3)));
        }

        try {
            $data = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw UnparseableResponse::because('not valid JSON (' . $e->getMessage() . ').');
        }

        if (! is_array($data)) {
            throw UnparseableResponse::because('the JSON is not an object or an array.');
        }

        return $data;
    }

    /**
     * @param array<string, Ai> $fields
     * @return array<string, mixed>|null
     */
    private function validRow (mixed $candidate, array $fields): ?array
    {
        if (! is_array($candidate)) {
            return NULL;
        }

        $row = [];

        foreach ($fields as $name => $field) {
            if (! array_key_exists($name, $candidate)) {
                return NULL;
            }

            try {
                $row[$name] = $field->normalize($candidate[$name]);
            } catch (UnexpectedValueException) {
                return NULL;
            }
        }

        return $row;
    }
}
