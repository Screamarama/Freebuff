<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('title')->default('Rental Agreement');
            $table->text('body');
            $table->text('footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('business_id', 'contract_templates_business_unique');
        });

        $boilerplate = <<<'TEXT'
RENTAL AGREEMENT

This Rental Agreement ("Agreement") is entered into by and between {{business_name}} ("Owner") and {{customer_name}} ("Renter").

1. BUSINESS DETAILS
Owner: {{business_name}}
Address: {{business_address}}
Phone: {{business_phone}} | Email: {{business_email}}

2. RENTAL DETAILS
Contract No: {{contract_number}}
Rental period: {{start_date}} to {{end_date}}
Total amount: {{total_amount}}

3. TERMS
3.1 The Renter agrees to return all items in the same condition, ordinary wear excepted.
3.2 Late returns incur a late fee as stated in the footer below.
3.3 The deposit paid will be applied against damage, loss, or late fees.
3.4 The Renter is responsible for loss or damage from pickup until return.

4. SIGNATURES
Owner ({{business_name}}): ______________________  Date: __________
Renter ({{customer_name}}): ______________________  Date: __________
TEXT;

        DB::table('contract_templates')->insert([
            'business_id' => null,
            'title' => 'Rental Agreement (Boilerplate)',
            'body' => $boilerplate,
            'footer' => 'Late fee: $25/day per overdue item. Deposit is refundable less damages/fees.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
