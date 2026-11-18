<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A charge that was put on a customer's account by mistake is reversed,
     * not deleted: the original charged_to_account_at stays, these columns say
     * when and why it came off.
     */
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->timestamp('charge_reversed_at')->nullable()->after('due_on');
            $table->string('charge_reversed_reason', 255)->nullable()->after('charge_reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn(['charge_reversed_at', 'charge_reversed_reason']);
        });
    }
};
