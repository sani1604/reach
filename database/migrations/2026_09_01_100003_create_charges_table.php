<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('charge_id')->nullable()->index(); // Shopify charge gid
            $table->text('confirmation_url')->nullable();      // approval page URL
            $table->string('type')->default('recurring');      // recurring | one_time
            $table->string('plan')->nullable();
            $table->string('status')->nullable();              // pending | active | declined | expired | cancelled
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();

            $table->timestamp('billing_on')->nullable();
            $table->timestamp('activated_on')->nullable();
            $table->timestamp('cancelled_on')->nullable();
            $table->timestamp('trial_ends_on')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
