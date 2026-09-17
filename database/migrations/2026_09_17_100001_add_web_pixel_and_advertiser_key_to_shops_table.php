<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a critical collision: shops.pixel_id was used for BOTH the merchant's
 * OpenAI Ads Pixel ID and the Shopify web-pixel GID returned by webPixelCreate.
 * After install, the GID overwrote the OpenAI ID — so pixelConfigured() looked
 * true but events never reached OpenAI (wrong id + wrong CAPI shape).
 *
 * Also adds the optional Advertiser API key (Ads Manager / api.ads.openai.com).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('web_pixel_id')->nullable()->after('capi_url');
            $table->text('advertiser_api_key')->nullable()->after('web_pixel_id');
        });

        // Move any Shopify GID that landed in pixel_id over to web_pixel_id and
        // clear pixel_id so the merchant can re-enter their real OpenAI Pixel ID.
        // Shopify GIDs look like: gid://shopify/WebPixel/123456789
        if (Schema::hasColumn('shops', 'pixel_id')) {
            $rows = DB::table('shops')
                ->whereNotNull('pixel_id')
                ->where('pixel_id', 'like', 'gid://%')
                ->get(['id', 'pixel_id']);

            foreach ($rows as $shop) {
                DB::table('shops')->where('id', $shop->id)->update([
                    'web_pixel_id' => $shop->pixel_id,
                    'pixel_id'     => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['web_pixel_id', 'advertiser_api_key']);
        });
    }
};
