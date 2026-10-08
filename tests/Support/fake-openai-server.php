<?php

/**
 * Router script for `php -S`: a tiny stand-in for an OpenAI-compatible chat-completions endpoint.
 *
 * A path prefix selects a behaviour:
 *   /v1/...        normal: valid rows
 *   /slow/v1/...   valid rows after a 300 ms delay (proves concurrency)
 *   /flaky/v1/...  the first two requests fail with HTTP 500, later ones succeed
 *   /auth/v1/...   requires "Authorization: Bearer secret-key", otherwise HTTP 401
 *   /garbage/v1/.. answers with prose instead of JSON
 *   /fenced/v1/..  wraps the JSON in a ```json code fence
 *
 * Environment: FAKE_OPENAI_STATE (counter file), FAKE_OPENAI_LOG (JSON-lines request log).
 */
$started = microtime(TRUE);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$stateFile = getenv('FAKE_OPENAI_STATE') ?: sys_get_temp_dir() . '/fake-openai-state.json';
$logFile = getenv('FAKE_OPENAI_LOG') ?: sys_get_temp_dir() . '/fake-openai-log.jsonl';

function reserve (string $file, int $count): array
{
    $handle = fopen($file, 'c+');
    flock($handle, LOCK_EX);

    $state = json_decode(stream_get_contents($handle) ?: '', TRUE) ?: ['requests' => 0, 'rows' => 0];
    $request = ++$state['requests'];
    $firstRow = $state['rows'] + 1;
    $state['rows'] += $count;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return [$request, $firstRow];
}

function respond (int $status, array $payload, float $started, string $logFile, string $path): void
{
    file_put_contents($logFile, json_encode([
        'path' => $path,
        'start' => $started,
        'end' => microtime(TRUE),
        'status' => $status,
    ]) . "\n", FILE_APPEND | LOCK_EX);

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

function sampleValue (string $name, array $field, int $n): mixed
{
    $min = $field['min'] ?? 1;

    return match ($field['type']) {
        'text' => ucfirst($name) . " sample {$n}",
        'integer' => $min + ($n % max(1, ($field['max'] ?? $min + 99) - $min + 1)),
        'float' => min($field['max'] ?? PHP_INT_MAX, round($min + ($n % 50) * 0.5, 2)),
        'boolean' => $n % 2 === 0,
        'date' => date('Y-m-d', strtotime('2025-01-01 +' . ($n % 365) . ' days')),
        'enum' => $field['options'][$n % count($field['options'])],
    };
}

if (! preg_match('#^/(?:(slow|flaky|auth|garbage|fenced)/)?v1/chat/completions$#', $path, $matches)) {
    respond(404, ['error' => ['message' => 'Not found']], $started, $logFile, $path);

    return;
}

$mode = $matches[1] ?? 'normal';
$body = json_decode(file_get_contents('php://input') ?: '', TRUE) ?: [];
$spec = json_decode($body['messages'][1]['content'] ?? '{}', TRUE) ?: [];
$count = max(1, (int)($spec['count'] ?? 1));

[$request, $firstRow] = reserve($stateFile, $count);

if ($mode === 'auth' && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer secret-key') {
    respond(401, ['error' => ['message' => 'Incorrect API key provided.']], $started, $logFile, $path);

    return;
}

if ($mode === 'flaky' && $request <= 2) {
    respond(500, ['error' => ['message' => 'Simulated upstream failure.']], $started, $logFile, $path);

    return;
}

if ($mode === 'slow') {
    usleep(300000);
}

if ($mode === 'garbage') {
    $content = "Sorry, I can't produce JSON today.";
} else {
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $row = [];

        foreach ($spec['fields'] ?? [] as $name => $field) {
            $row[$name] = sampleValue($name, $field, $firstRow + $i);
        }

        $rows[] = $row;
    }

    $content = json_encode(['rows' => $rows]);

    if ($mode === 'fenced') {
        $content = "```json\n{$content}\n```";
    }
}

respond(200, [
    'id' => 'fake-' . $request,
    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
], $started, $logFile, $path);
