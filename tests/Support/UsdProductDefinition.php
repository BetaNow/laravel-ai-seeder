<?php

namespace BetaNow\AiSeeder\Tests\Support;

use Workbench\App\Seeding\ProductDefinition;

/**
 * ProductDefinition with a USD currency literal and its own context, so its rows and requests can be told apart.
 */
class UsdProductDefinition extends ProductDefinition
{
    public function context (): string
    {
        return parent::context() . ', priced in US dollars';
    }

    public function fields (): array
    {
        return array_replace(parent::fields(), ['currency' => 'USD']);
    }
}
