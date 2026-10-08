<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Product;
use Workbench\App\Seeding\ProductDefinition;

/**
 * Wires the demo app: a file-based SQLite database (kept in Testbench's git-ignored skeleton database path, created
 * on first boot) and the Product definition.
 *
 * @author BetaNow
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register (): void
    {
    }

    public function boot (): void
    {
        $database = database_path('demo.sqlite');

        if (! is_file($database)) {
            File::ensureDirectoryExists(dirname($database));
            File::put($database, '');
        }

        config([
            'database.default' => 'demo',
            'database.connections.demo' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => TRUE,
            ],
            'ai-seeder.models' => [Product::class => ProductDefinition::class],
        ]);
    }
}
