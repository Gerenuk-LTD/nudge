<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('idempotency_key', 255)->nullable()->after('data');
            $table->timestamp('triggered_at')->nullable()->after('occurred_at');

            // Null keys never collide, so existing rows and events sent without a key are unaffected.
            $table->unique(['workspace_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'triggered_at']);
        });
    }
};
