<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();

            // Rider identification
            $table->string('rider_code', 30)->index();

            // Start coordinates (set when trip begins, never changes)
            $table->decimal('start_lat', 10, 7);
            $table->decimal('start_lng', 10, 7);

            // Live position (updated continuously while riding)
            $table->decimal('current_lat', 10, 7);
            $table->decimal('current_lng', 10, 7);

            // End coordinates (set when trip ends, null while active)
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();

            // Trip lifecycle
            $table->enum('status', ['active', 'ended'])->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
