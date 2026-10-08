<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up (): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('category');
            $table->decimal('price', 8, 2);
            $table->boolean('in_stock');
            $table->date('released_at');
            $table->string('slug')->unique();
            $table->string('currency', 3);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down (): void
    {
        Schema::dropIfExists('products');
    }
};
