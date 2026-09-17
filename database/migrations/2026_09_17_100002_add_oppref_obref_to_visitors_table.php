<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI Ads click / browser identifiers captured by the Measurement Pixel.
 * oppref  — attribution id from the ad click URL / __oppref cookie
 * obref   — opaque browser reference from the __obref cookie
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('oppref')->nullable()->after('fbp');
            $table->string('obref')->nullable()->after('oppref');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['oppref', 'obref']);
        });
    }
};
