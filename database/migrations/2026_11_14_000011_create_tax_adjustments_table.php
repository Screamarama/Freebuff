<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One dated delta per change, so a later change cannot displace an
        // earlier month's adjustment in the tax report.
        Schema::create('tax_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_contract_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('adjusted_at');
            $table->timestamps();

            $table->index(['rental_contract_id', 'adjusted_at']);
        });

        // Carry any adjustment already recorded on the agreement across.
        DB::table('rental_contracts')
            ->whereNotNull('tax_adjusted_at')
            ->orderBy('id')
            ->each(function ($contract) {
                $amount = round((float) $contract->tax_amount - (float) ($contract->charged_tax_amount ?? 0), 2);

                if ($amount == 0) {
                    return;
                }

                DB::table('tax_adjustments')->insert([
                    'rental_contract_id' => $contract->id,
                    'amount' => $amount,
                    'adjusted_at' => $contract->tax_adjusted_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('tax_adjusted_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->timestamp('tax_adjusted_at')->nullable();
        });

        DB::table('tax_adjustments')
            ->orderBy('adjusted_at')
            ->orderBy('id')
            ->get()
            ->each(function ($adjustment) {
                DB::table('rental_contracts')
                    ->where('id', $adjustment->rental_contract_id)
                    ->update(['tax_adjusted_at' => $adjustment->adjusted_at]);
            });

        Schema::dropIfExists('tax_adjustments');
    }
};
