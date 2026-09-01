<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visitor identity bridge. The browser pixel assigns each visitor a `vid`
     * and reports it alongside `_fbc`/`_fbp` click IDs and any email/phone it
     * learns. This lets server-side Purchase events (order webhooks) be joined
     * back to click IDs for cross-device matching.
     */
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('vid')->index();          // client-side visitor id (_reach_vid)
            $table->string('fbc')->nullable();       // Meta-style click id
            $table->string('fbp')->nullable();       // Meta-style browser id
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('order_id')->nullable();  // set when enrichment precedes the order webhook

            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'vid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
