<?php

namespace Vendor\AiSeeder\Tests\Support;

use Workbench\App\Seeding\ProductDefinition;

/**
 * Forces every row to the same slug so the unique index rejects the second insert.
 */
class DuplicateSlugDefinition extends ProductDefinition
{
    public function fields (): array
    {
        return ['slug' => 'always-the-same'] + parent::fields();
    }
}
