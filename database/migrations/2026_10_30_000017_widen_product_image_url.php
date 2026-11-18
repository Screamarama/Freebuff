<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pasted image links (search redirects, signed CDN URLs) regularly run
     * past 255 characters, which truncated inserts at the column level.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
        });
    }
};
