<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer')->after('email');
            $table->foreignId('business_id')->nullable()->after('role')->constrained('businesses')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->string('phone', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['role', 'business_id', 'is_active', 'phone']);
        });
    }
};

