<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency log for Shopify webhook deliveries. Shopify retries
     * deliveries on failure, so we key on the X-Shopify-Webhook-Id header
     * and ignore anything we've already processed.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('webhook_id')->index();
            $table->string('topic')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'webhook_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
