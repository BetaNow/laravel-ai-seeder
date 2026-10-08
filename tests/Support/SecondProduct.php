<?php

namespace BetaNow\AiSeeder\Tests\Support;

use Workbench\App\Models\Product;

/**
 * A second model on the products table, so one run can seed two registered models.
 */
class SecondProduct extends Product
{
    protected $table = 'products';
}
