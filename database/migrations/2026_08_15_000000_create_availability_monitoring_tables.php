<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('suspected');
            $table->timestamp('opened_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_checked_at');
            $table->string('initial_outcome');
            $table->string('last_outcome');
            $table->unsignedInteger('failed_checks')->default(0);
            $table->unsignedInteger('successful_checks')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->unsignedSmallInteger('worst_http_status')->nullable();
            $table->string('notification_state')->default('not_notified');
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['court_id', 'status']);
            $table->index(['status', 'opened_at']);
        });

        Schema::create('availability_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('request_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('availability_incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('endpoint_type')->default('case_list');
            $table->text('url');
            $table->timestamp('checked_at');
            $table->string('outcome');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->string('probe_node')->nullable();
            $table->timestamps();

            $table->unique('request_log_id');
            $table->index(['court_id', 'checked_at']);
            $table->index(['outcome', 'checked_at']);
            $table->index(['source', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_checks');
        Schema::dropIfExists('availability_incidents');
    }
};
