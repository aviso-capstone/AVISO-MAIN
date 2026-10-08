<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('confidence_threshold')->default(0);
            $table->unsignedInteger('items_per_page')->default(15);
            $table->string('default_sort')->default('detected_at');
            $table->json('emergency_hazard_types');
            $table->timestamps();
        });

        DB::table('system_settings')->insert([
            'confidence_threshold'   => 0,
            'items_per_page'         => 15,
            'default_sort'           => 'detected_at',
            'emergency_hazard_types' => json_encode(['Pothole', 'Road Excavation', 'Road Barrier']),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
