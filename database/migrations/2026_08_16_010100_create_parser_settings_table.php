<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parser_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('request_interval_ms')->default(10000);
            $table->unsignedInteger('connect_timeout_seconds')->default(10);
            $table->unsignedInteger('directory_timeout_seconds')->default(45);
            $table->unsignedInteger('forbidden_cooldown_seconds')->default(21600);
            $table->unsignedInteger('rate_limit_cooldown_seconds')->default(21600);
            $table->unsignedInteger('timeout_circuit_threshold')->default(3);
            $table->unsignedInteger('timeout_cooldown_seconds')->default(3600);
            $table->boolean('monitoring_enabled')->default(false);
            $table->unsignedInteger('monitor_interval_minutes')->default(10);
            $table->unsignedInteger('monitor_reuse_window_minutes')->default(10);
            $table->unsignedInteger('monitor_timeout_seconds')->default(45);
            $table->unsignedInteger('monitor_connect_timeout_seconds')->default(10);
            $table->boolean('regular_scheduling_enabled')->default(true);
            $table->unsignedInteger('regular_slice_seconds')->default(50);
            $table->unsignedInteger('capacity_utilization_percent')->default(80);
            $table->unsignedInteger('base_budget_percent')->default(40);
            $table->unsignedInteger('maximum_court_share_percent')->default(10);
            $table->unsignedInteger('initial_maximum_case_attempts')->default(3);
            $table->unsignedInteger('regular_maximum_case_attempts')->default(3);
            $table->unsignedInteger('regular_failure_retry_minutes')->default(60);
            $table->unsignedInteger('regular_recheck_interval_days')->default(30);
            $table->unsignedInteger('regular_recheck_starvation_days')->default(45);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_settings');
    }
};
