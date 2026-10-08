<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('hazard_logs', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Trip::class);
            $table->dropColumn('trip_id');
        });
    }
};
