<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->onDelete('cascade');
            $table->string('primary_color', 20)->default('#3b82f6');
            $table->string('secondary_color', 20)->default('#64748b');
            $table->string('accent_color', 20)->default('#10b981');
            $table->string('text_color', 20)->default('#1f2937');
            $table->string('background_color', 20)->default('#ffffff');
            $table->string('font_family', 100)->default('Inter, sans-serif');
            $table->string('heading_font', 100)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('header_image_url')->nullable();
            $table->string('background_image_url')->nullable();
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('tagline')->nullable();
            $table->text('custom_css')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};

