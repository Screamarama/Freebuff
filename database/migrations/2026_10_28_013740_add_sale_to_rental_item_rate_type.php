<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->enum('rate_type', ['hour', 'day', 'week', 'month', 'sale'])->default('day')->change();
        });
    }

    public function down(): void
    {
        DB::table('rental_items')->where('rate_type', 'sale')->delete();

        Schema::table('rental_items', function (Blueprint $table) {
            $table->enum('rate_type', ['hour', 'day', 'week', 'month'])->default('day')->change();
        });
    }
};
