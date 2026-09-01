<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // OpenAI/Meta taxonomy: PageView, ViewContent, AddToCart,
            // InitiateCheckout, Purchase (plus raw Shopify names kept in payload)
            $table->string('event_name')->index();

            $table->string('event_id')->index();          // CAPI event_id (dedup)
            $table->string('dedup_key')->nullable();      // source:event_name:key
            $table->unique(['shop_id', 'dedup_key'], 'events_shop_dedup_unique');

            $table->string('source')->default('browser'); // browser | server

            $table->string('order_id')->nullable()->index();
            $table->string('order_name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('value', 14, 2)->nullable();

            $table->timestamp('occurred_at')->nullable()->index();
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
