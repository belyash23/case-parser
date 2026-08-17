<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('courts')
            ->where('min_request_interval_ms', '<', 10000)
            ->update(['min_request_interval_ms' => 10000]);
    }

    public function down(): void
    {
        // Existing values are intentionally not reduced during rollback.
    }
};
