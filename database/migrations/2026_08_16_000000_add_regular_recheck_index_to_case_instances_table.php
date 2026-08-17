<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_instances', function (Blueprint $table): void {
            $table->index(
                ['court_id', 'court_instance_status_normalized', 'updated_at'],
                'case_instances_regular_recheck_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('case_instances', function (Blueprint $table): void {
            $table->dropIndex('case_instances_regular_recheck_index');
        });
    }
};
