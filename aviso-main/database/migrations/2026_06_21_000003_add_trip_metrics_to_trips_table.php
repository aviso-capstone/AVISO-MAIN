<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('total_distance_km', 8, 3)->nullable()->after('route_points');
            $table->unsignedInteger('duration_minutes')->nullable()->after('total_distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['total_distance_km', 'duration_minutes']);
        });
    }
};
