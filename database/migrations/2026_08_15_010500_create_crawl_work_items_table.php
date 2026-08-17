<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('work_type');
            $table->string('status')->default('pending');
            $table->string('deduplication_key', 64);
            $table->date('target_date')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('request_cost')->default(0);
            $table->json('payload_json')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['crawl_campaign_id', 'deduplication_key'], 'crawl_work_campaign_dedup_unique');
            $table->index(['crawl_campaign_id', 'status', 'available_at', 'priority'], 'crawl_work_schedule_index');
            $table->index(['court_id', 'status', 'work_type'], 'crawl_work_court_status_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_work_items');
    }
};
