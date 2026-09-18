<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-app support: the same myshopify domain may install both the private
 * Reach app and the public PixelAI app. Scope shop rows by app_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'app_key')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->string('app_key', 32)->default('reach')->after('id')->index();
            });
        }

        if (Schema::hasColumn('shops', 'app_key')) {
            DB::table('shops')->where(function ($q) {
                $q->whereNull('app_key')->orWhere('app_key', '');
            })->update(['app_key' => 'reach']);
        }

        // Rebuild uniqueness: (app_key, shopify_domain).
        $sm = Schema::getConnection()->getDoctrineSchemaManager() ?? null;
        // Avoid doctrine dependency — try/catch drop.
        Schema::table('shops', function (Blueprint $table) {
            foreach (['shops_shopify_domain_unique', 'shops_app_domain_unique'] as $index) {
                try {
                    $table->dropUnique($index);
                } catch (\Throwable) {
                    //
                }
            }
            try {
                $table->dropUnique(['shopify_domain']);
            } catch (\Throwable) {
                //
            }
        });

        Schema::table('shops', function (Blueprint $table) {
            // Ensure unique composite exists.
            try {
                $table->unique(['app_key', 'shopify_domain'], 'shops_app_domain_unique');
            } catch (\Throwable) {
                // already present
            }
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            try {
                $table->dropUnique('shops_app_domain_unique');
            } catch (\Throwable) {
                //
            }

            if (Schema::hasColumn('shops', 'app_key')) {
                $table->dropColumn('app_key');
            }

            try {
                $table->unique('shopify_domain');
            } catch (\Throwable) {
                //
            }
        });
    }
};
