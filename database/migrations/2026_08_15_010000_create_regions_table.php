<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->default('sudrf');
            $table->unsignedSmallInteger('sudrf_region_id')->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'is_enabled']);
            $table->index(['sync_status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
