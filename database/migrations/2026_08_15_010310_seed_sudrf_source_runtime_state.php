<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('source_runtime_states')->insertOrIgnore([
            'source_type' => 'sudrf',
            'circuit_status' => 'closed',
            'consecutive_timeouts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('source_runtime_states')
            ->where('source_type', 'sudrf')
            ->whereNull('active_crawl_campaign_id')
            ->delete();
    }
};
