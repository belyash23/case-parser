<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_crawl_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('court_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('initial_cursor_date')->nullable();
            $table->date('head_cursor_date')->nullable();
            $table->date('backlog_cursor_date')->nullable();
            $table->boolean('has_backlog')->default(false);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_at')->nullable();
            $table->timestamp('next_eligible_at')->nullable();
            $table->json('stats_json')->nullable();
            $table->timestamps();

            $table->index(['has_backlog', 'next_eligible_at']);
            $table->index('last_successful_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_crawl_states');
    }
};
