<?php

return [
    'default_driver' => env('AI_SEEDER_DRIVER', 'openai'),

    // Rows per model when --count / the $count argument is omitted.
    'default_count' => (int)env('AI_SEEDER_DEFAULT_COUNT', 10),

    // Ordered map of Eloquent model => definition class. Seeding all models follows this order,
    // so list parents before the models that depend on them.
    'models' => [
        // App\Models\Product::class => App\Seeding\ProductDefinition::class,
    ],

    'drivers' => [
        'openai' => [
            'api_key' => env('AI_SEEDER_OPENAI_API_KEY', env('OPENAI_API_KEY')),
            'endpoint' => env('AI_SEEDER_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'model' => env('AI_SEEDER_OPENAI_MODEL', 'gpt-4o-mini'),
            'batch_size' => (int)env('AI_SEEDER_OPENAI_BATCH_SIZE', 10),
            'max_concurrency' => (int)env('AI_SEEDER_OPENAI_MAX_CONCURRENCY', 4),
            'json_mode' => (bool)env('AI_SEEDER_OPENAI_JSON_MODE', TRUE),
            'timeout' => (int)env('AI_SEEDER_OPENAI_TIMEOUT', 60),
            'retries' => (int)env('AI_SEEDER_OPENAI_RETRIES', 3),
            'retry_delay' => (int)env('AI_SEEDER_OPENAI_RETRY_DELAY', 500),
            'requires_api_key' => TRUE,
        ],

        // Any OpenAI-compatible server: Ollama, LM Studio, llama.cpp ...
        'local' => [
            'api_key' => env('AI_SEEDER_LOCAL_API_KEY'),
            'endpoint' => env('AI_SEEDER_LOCAL_ENDPOINT', 'http://localhost:11434/v1/chat/completions'),
            'model' => env('AI_SEEDER_LOCAL_MODEL', 'mistral'),
            'batch_size' => (int)env('AI_SEEDER_LOCAL_BATCH_SIZE', 10),
            'max_concurrency' => (int)env('AI_SEEDER_LOCAL_MAX_CONCURRENCY', 4),
            'json_mode' => (bool)env('AI_SEEDER_LOCAL_JSON_MODE', FALSE),
            'timeout' => (int)env('AI_SEEDER_LOCAL_TIMEOUT', 120),
            'retries' => (int)env('AI_SEEDER_LOCAL_RETRIES', 3),
            'retry_delay' => (int)env('AI_SEEDER_LOCAL_RETRY_DELAY', 500),
            'requires_api_key' => FALSE,
        ],
    ],
];
