<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026 Shopify policy: public apps must use *expiring* offline access tokens
 * backed by refresh tokens (required for new public apps since April 1, 2026,
 * and for every public app by January 1, 2027).
 *
 * Shopify issues:
 *   access_token        — expires (expires_in, typically 3600s)
 *   refresh_token       — rotates on use (refresh_token_expires_in, ~90 days)
 *
 * We persist both plus their deadlines so background jobs can refresh tokens
 * without merchant interaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('token_expires_at');
            $table->string('token_scopes')->nullable()->after('refresh_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'refresh_token',
                'token_expires_at',
                'refresh_token_expires_at',
                'token_scopes',
            ]);
        });
    }
};
