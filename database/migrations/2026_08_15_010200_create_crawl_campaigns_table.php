<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->default('sudrf');
            $table->string('mode');
            $table->string('status')->default('pending');
            $table->date('window_from')->nullable();
            $table->date('window_to')->nullable();
            $table->json('settings_json')->nullable();
            $table->unsignedBigInteger('request_budget')->nullable();
            $table->unsignedBigInteger('requests_used')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'status']);
            $table->index(['mode', 'status']);
            $table->index(['window_from', 'window_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_campaigns');
    }
};
