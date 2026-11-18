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
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('postal_address')->nullable()->after('name');
            $table->boolean('requires_campaign_approval')->default(false)->after('postal_address');
            $table->timestamp('sending_paused_at')->nullable()->after('requires_campaign_approval');
            $table->string('sending_paused_reason')->nullable()->after('sending_paused_at');
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->text('credentials');
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_token', 64)->unique();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->unsignedInteger('max_per_second')->default(10);
            $table->unsignedInteger('daily_limit')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('reason');
            $table->string('detail')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('editor')->default('html');
            $table->json('editor_state')->nullable();
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->string('audience_type')->default('lists');
            $table->json('list_ids')->nullable();
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('complained_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('campaign_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('subject');
            $table->text('preheader')->nullable();
            $table->string('from_name');
            $table->string('from_email');
            $table->string('reply_to')->nullable();
            $table->longText('html');
            $table->longText('text');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('unique_clicks_count')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'url_hash']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('status')->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
            $table->index('provider_message_id');
        });

        // Append-only. No foreign keys, so the table can be partitioned by month later.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('campaign_recipient_id')->nullable();
            $table->string('type');
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['workspace_id', 'type', 'occurred_at']);
            $table->index(['contact_id', 'occurred_at']);
            $table->index(['campaign_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_links');
        Schema::dropIfExists('campaign_snapshots');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('providers');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['postal_address', 'requires_campaign_approval', 'sending_paused_at', 'sending_paused_reason']);
        });
    }
};
