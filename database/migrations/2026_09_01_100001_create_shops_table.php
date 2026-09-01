<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_domain')->unique();
            $table->text('access_token')->nullable();

            // plan = 'free' | 'basic'
            $table->string('plan')->default('free')->index();
            $table->string('plan_status')->nullable()->index(); // active | trial | cancelled | frozen

            // Merchant credentials for the OpenAI Ads integration
            $table->string('pixel_id')->nullable();   // OpenAI Ads pixel ID
            $table->text('capi_token')->nullable();   // OpenAI Conversions API key

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();

            // Usage metering for plan limits
            $table->unsignedBigInteger('monthly_event_count')->default(0);
            $table->timestamp('events_reset_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
