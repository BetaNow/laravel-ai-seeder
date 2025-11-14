<?php

return [
    'default_driver' => env('AI_SEEDER_DRIVER', 'openai'),

    'drivers' => [
        'openai' => [
            'api_key' => env('AI_SEEDER_OPENAI_API_KEY', env('OPENAI_API_KEY')),
            'endpoint' => env('AI_SEEDER_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'model' => env('AI_SEEDER_OPENAI_MODEL', 'gpt-4o-mini'),
            'batch_size' => (int)env('AI_SEEDER_OPENAI_BATCH_SIZE', 10),
            'max_concurrency' => (int)env('AI_SEEDER_OPENAI_MAX_CONCURRENCY', 4),
        ],

        'local' => [
            'api_key' => env('AI_SEEDER_LOCAL_API_KEY'),
            'endpoint' => env('AI_SEEDER_LOCAL_ENDPOINT', 'http://localhost:8000/api/ai'),
            'model' => env('AI_SEEDER_LOCAL_MODEL', 'mistral'),
            'batch_size' => (int)env('AI_SEEDER_LOCAL_BATCH_SIZE', 10),
            'max_concurrency' => (int)env('AI_SEEDER_LOCAL_MAX_CONCURRENCY', 4),
        ],
    ],
];
