<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crash_alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_event_id')->constrained('rider_events')->cascadeOnDelete();
            $table->foreignId('emergency_contact_id')->constrained('emergency_contacts')->cascadeOnDelete();
            $table->enum('method', ['sms_gateway', 'gsm_fallback']);
            $table->timestamp('sent_at');
            $table->enum('status', ['sent', 'failed', 'delivered'])->default('sent');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crash_alert_logs');
    }
};
