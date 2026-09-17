<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('feed_token', 64)->nullable()->unique()->after('advertiser_api_key');
            $table->unsignedInteger('feed_item_count')->default(0)->after('feed_token');
            $table->unsignedInteger('feed_issue_count')->default(0)->after('feed_item_count');
            $table->timestamp('feed_synced_at')->nullable()->after('feed_issue_count');
            $table->string('feed_status')->nullable()->after('feed_synced_at'); // ready | issues | empty | error
            $table->json('feed_meta')->nullable()->after('feed_status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'feed_token',
                'feed_item_count',
                'feed_issue_count',
                'feed_synced_at',
                'feed_status',
                'feed_meta',
            ]);
        });
    }
};
