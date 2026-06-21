<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('court_level')->nullable();
            $table->string('court_type')->nullable();
            $table->string('source_type')->default('sudrf');
            $table->string('base_url');
            $table->string('layout_type')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('min_request_interval_ms')->default(3000);
            $table->unsignedTinyInteger('max_parallel_requests')->default(1);
            $table->unsignedInteger('timeout_ms')->default(30000);
            $table->unsignedTinyInteger('retry_count')->default(2);
            $table->decimal('backoff_multiplier', 5, 2)->default(1.80);
            $table->unsignedInteger('crawl_priority')->default(100);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_successful_crawl_at')->nullable();
            $table->timestamps();

            $table->unique('base_url');
            $table->index(['source_type', 'status']);
            $table->index(['is_enabled', 'crawl_priority']);
        });

        Schema::create('parser_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_type');
            $table->string('status')->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('parser_version')->nullable();
            $table->json('settings_json')->nullable();
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('successful_requests')->default(0);
            $table->unsignedInteger('failed_requests')->default(0);
            $table->unsignedInteger('calendar_days_count')->default(0);
            $table->unsignedInteger('calendar_case_links_count')->default(0);
            $table->unsignedInteger('new_cases_count')->default(0);
            $table->unsignedInteger('updated_cases_count')->default(0);
            $table->unsignedInteger('new_events_count')->default(0);
            $table->unsignedInteger('out_of_window_cases_count')->default(0);
            $table->unsignedInteger('training_candidate_cases_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();

            $table->index(['run_type', 'status']);
            $table->index('started_at');
        });

        Schema::create('request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->text('url');
            $table->string('url_hash', 64)->index();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['court_id', 'created_at']);
            $table->index(['status_code', 'created_at']);
        });

        Schema::create('raw_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('url_hash', 64)->index();
            $table->string('page_type');
            $table->timestamp('fetched_at');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_hash', 64)->index();
            $table->string('sanitized_html_path')->nullable();
            $table->string('parser_version')->nullable();
            $table->timestamps();

            $table->unique(['url_hash', 'content_hash'], 'raw_pages_url_content_unique');
            $table->index(['court_id', 'page_type', 'fetched_at']);
        });

        Schema::create('cases', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_case_number')->nullable();
            $table->foreignId('primary_court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->text('category_raw')->nullable();
            $table->string('category_normalized')->nullable();
            $table->string('proceeding_type')->nullable();
            $table->date('received_date')->nullable();
            $table->date('final_observed_date')->nullable();
            $table->date('observation_window_from')->nullable();
            $table->date('observation_window_to')->nullable();
            $table->boolean('is_training_candidate')->default(false);
            $table->string('discovered_via')->nullable();
            $table->boolean('has_appeal')->default(false);
            $table->boolean('has_cassation')->default(false);
            $table->timestamps();

            $table->unique(['primary_court_id', 'normalized_case_number'], 'cases_court_number_unique');
            $table->index(['is_training_candidate', 'received_date', 'final_observed_date'], 'cases_training_window_index');
        });

        Schema::create('case_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_page_id')->nullable()->constrained('raw_pages')->nullOnDelete();
            $table->string('source_type')->default('sudrf');
            $table->text('source_url');
            $table->string('source_url_hash', 64)->index();
            $table->string('external_case_number')->nullable();
            $table->string('case_uid')->nullable();
            $table->string('external_case_id')->nullable();
            $table->string('instance_level')->default('first');
            $table->string('status_raw')->nullable();
            $table->string('status_normalized')->nullable();
            $table->string('result_raw')->nullable();
            $table->string('result_normalized')->nullable();
            $table->date('started_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('category_raw')->nullable();
            $table->string('category_normalized')->nullable();
            $table->timestamps();

            $table->unique(['court_id', 'case_uid'], 'case_instances_court_uid_unique');
            $table->unique(['court_id', 'source_url_hash'], 'case_instances_court_url_unique');
            $table->index(['court_id', 'instance_level']);
        });

        Schema::create('case_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_instance_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('party_type')->default('unknown');
            $table->string('source_role')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->timestamps();

            $table->index(['role', 'party_type']);
        });

        Schema::create('case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_instance_id')->constrained()->cascadeOnDelete();
            $table->date('event_date')->nullable();
            $table->unsignedInteger('event_order')->default(0);
            $table->text('event_type_raw')->nullable();
            $table->string('event_type_normalized')->default('unknown');
            $table->text('event_result_raw')->nullable();
            $table->string('event_result_normalized')->nullable();
            $table->text('source_url')->nullable();
            $table->string('event_fingerprint', 64);
            $table->timestamps();

            $table->unique(['case_instance_id', 'event_fingerprint'], 'case_events_instance_fingerprint_unique');
            $table->index(['event_type_normalized', 'event_date']);
        });

        Schema::create('case_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_instance_id')->constrained()->cascadeOnDelete();
            $table->text('document_type_raw')->nullable();
            $table->string('document_type_normalized')->nullable();
            $table->string('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_kind')->nullable();
            $table->text('source_url')->nullable();
            $table->string('document_fingerprint', 64);
            $table->timestamps();

            $table->unique(['case_instance_id', 'document_fingerprint'], 'case_documents_instance_fingerprint_unique');
        });

        Schema::create('case_chain_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_instance_id')->constrained('case_instances')->cascadeOnDelete();
            $table->foreignId('target_instance_id')->constrained('case_instances')->cascadeOnDelete();
            $table->string('link_type')->default('unknown');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->json('evidence_json')->nullable();
            $table->timestamps();

            $table->unique(['source_instance_id', 'target_instance_id', 'link_type'], 'case_chain_links_unique');
        });

        Schema::create('parser_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parser_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->text('url')->nullable();
            $table->string('error_type');
            $table->text('error_message')->nullable();
            $table->longText('traceback')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['error_type', 'occurred_at']);
            $table->index(['court_id', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parser_errors');
        Schema::dropIfExists('case_chain_links');
        Schema::dropIfExists('case_documents');
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('case_parties');
        Schema::dropIfExists('case_instances');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('raw_pages');
        Schema::dropIfExists('request_logs');
        Schema::dropIfExists('parser_runs');
        Schema::dropIfExists('courts');
    }
};
