<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->index(['area', 'type'], 'hazard_logs_area_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropIndex('hazard_logs_area_type_index');
        });
    }
};
