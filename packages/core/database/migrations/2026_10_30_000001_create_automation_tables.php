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
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->json('definition');
            $table->boolean('allow_reentry')->default(false);
            $table->unsignedInteger('entered_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'trigger_type']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('current_step_id')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->json('context')->nullable();
            $table->string('exit_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
            $table->index(['automation_id', 'contact_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('kind')->default('broadcast')->after('workspace_id');
            $table->foreignId('automation_id')->nullable()->after('kind')->constrained()->cascadeOnDelete();
            $table->string('automation_step_id')->nullable()->after('automation_id');
            $table->boolean('ab_enabled')->default(false)->after('track_clicks');
            $table->string('ab_subject_b')->nullable()->after('ab_enabled');
            $table->longText('ab_html_b')->nullable()->after('ab_subject_b');
            $table->unsignedTinyInteger('ab_sample_percent')->default(20)->after('ab_html_b');
            $table->unsignedInteger('ab_wait_minutes')->default(240)->after('ab_sample_percent');
            $table->string('ab_metric')->default('clicks')->after('ab_wait_minutes');
            $table->char('ab_winner', 1)->nullable()->after('ab_metric');
            $table->timestamp('ab_decided_at')->nullable()->after('ab_winner');

            $table->index(['workspace_id', 'kind']);
        });

        Schema::table('campaign_snapshots', function (Blueprint $table) {
            $table->string('subject_b')->nullable()->after('subject');
            $table->longText('html_b')->nullable()->after('html');
            $table->longText('text_b')->nullable()->after('text');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->char('variant', 1)->nullable()->after('email');
            // 0 for broadcasts, so (campaign, contact, run) stays unique for both broadcasts and automations.
            $table->unsignedBigInteger('automation_run_id')->default(0)->after('contact_id');
            $table->unique(['campaign_id', 'contact_id', 'automation_run_id']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'contact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'contact_id', 'automation_run_id']);
            $table->dropColumn(['variant', 'automation_run_id']);
        });

        Schema::table('campaign_snapshots', function (Blueprint $table) {
            $table->dropColumn(['subject_b', 'html_b', 'text_b']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'kind']);
            $table->dropConstrainedForeignId('automation_id');
            $table->dropColumn(['kind', 'automation_step_id', 'ab_enabled', 'ab_subject_b', 'ab_html_b', 'ab_sample_percent', 'ab_wait_minutes', 'ab_metric', 'ab_winner', 'ab_decided_at']);
        });

        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
    }
};
