<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_runtime_states', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->unique();
            $table->foreignId('active_crawl_campaign_id')->nullable()->constrained('crawl_campaigns')->nullOnDelete();
            $table->string('circuit_status')->default('closed');
            $table->timestamp('last_request_started_at', 3)->nullable();
            $table->timestamp('next_request_at', 3)->nullable();
            $table->timestamp('circuit_opened_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->string('circuit_reason')->nullable();
            $table->unsignedInteger('consecutive_timeouts')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->index(['circuit_status', 'cooldown_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runtime_states');
    }
};
