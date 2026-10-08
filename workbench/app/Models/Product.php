<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'float',
        'in_stock' => 'boolean',
        'released_at' => 'date',
    ];
}
