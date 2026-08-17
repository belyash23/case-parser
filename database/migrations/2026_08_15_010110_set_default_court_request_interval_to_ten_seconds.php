<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->unsignedInteger('min_request_interval_ms')->default(10000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table): void {
            $table->unsignedInteger('min_request_interval_ms')->default(3000)->change();
        });
    }
};
