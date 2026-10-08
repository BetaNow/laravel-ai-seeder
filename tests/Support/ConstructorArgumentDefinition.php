<?php

namespace BetaNow\AiSeeder\Tests\Support;

use Workbench\App\Seeding\ProductDefinition;

/**
 * A definition whose constructor needs an argument, so `new $class` would throw an ArgumentCountError.
 */
class ConstructorArgumentDefinition extends ProductDefinition
{
    public function __construct (public string $currency)
    {
    }
}
