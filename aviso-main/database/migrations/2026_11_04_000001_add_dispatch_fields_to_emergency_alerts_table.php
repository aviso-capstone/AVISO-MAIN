<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch tracking: the admin, acting as dispatcher, records who was called
 * and how the response went. "Help dispatched" reuses the existing
 * `acknowledged` status, so the status enum is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('status');
            $table->string('responder_agency', 40)->nullable()->after('dispatched_at');
            $table->string('responder_agency_other', 60)->nullable()->after('responder_agency');
            $table->string('responder_first_name', 50)->nullable()->after('responder_agency_other');
            $table->string('responder_last_name', 50)->nullable()->after('responder_first_name');
            // null = Unknown, 61 = "More than 1 hour".
            $table->unsignedSmallInteger('eta_minutes')->nullable()->after('responder_last_name');
            $table->timestamp('on_scene_at')->nullable()->after('eta_minutes');
            $table->string('outcome', 30)->nullable()->after('on_scene_at');
            $table->string('resolution_note', 300)->nullable()->after('outcome');
            $table->foreignId('handled_by')->nullable()->after('resolution_note')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('emergency_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn([
                'dispatched_at',
                'responder_agency',
                'responder_agency_other',
                'responder_first_name',
                'responder_last_name',
                'eta_minutes',
                'on_scene_at',
                'outcome',
                'resolution_note',
            ]);
        });
    }
};
