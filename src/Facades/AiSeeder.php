<?php

namespace Vendor\AiSeeder\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * AiSeeder Facade for accessing the AiManager service.
 *
 * @author BetaNow
 */
class AiSeeder extends Facade
{
    /**
     * Retrieve the name of the component being accessed.
     *
     * @return string The string identifier for the facade accessor.
     */
    protected static function getFacadeAccessor (): string
    {
        return 'ai-seeder';
    }
}
