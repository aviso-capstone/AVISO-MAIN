<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hazard no longer there" reports: a rider sends a photo of a saved hazard's
 * spot as proof that it is gone, and an admin confirms (resolves the hazard) or
 * rejects it. The hazard row itself is never deleted — it only becomes resolved,
 * with the reason kept in `hazard_logs.resolved_reason`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hazard_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hazard_log_id')->constrained('hazard_logs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('photo_path');
            $table->string('note', 200)->nullable();
            // The rider's position when reporting (null when the phone had no GPS fix).
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // Rider-to-hazard distance in whole meters, shown to the admin.
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One report per rider per hazard.
            $table->unique(['hazard_log_id', 'user_id']);
            $table->index('status');
        });

        Schema::table('hazard_logs', function (Blueprint $table) {
            // fixed | not_hazard | other — why a hazard was resolved.
            $table->string('resolved_reason', 20)->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropColumn('resolved_reason');
        });

        Schema::dropIfExists('hazard_reports');
    }
};
