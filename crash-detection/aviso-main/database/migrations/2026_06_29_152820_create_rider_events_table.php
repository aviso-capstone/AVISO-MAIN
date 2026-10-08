<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rider_code')->comment('Denormalized username for broadcast performance');
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->enum('event_type', ['normal', 'hard_braking', 'road_bump', 'crash']);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('acceleration_peak', 8, 4)->comment('Peak G-force reading from BNO055 sensor');
            $table->timestamp('detected_at');
            $table->enum('status', ['detected', 'alerted', 'acknowledged', 'resolved'])->default('detected');
            $table->timestamp('acknowledged_at')->nullable()->comment('Crash events only');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_events');
    }
};
