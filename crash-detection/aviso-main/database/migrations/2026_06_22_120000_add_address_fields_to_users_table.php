<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('street')->nullable()->after('address');
            $table->string('barangay_id', 10)->nullable()->after('street')->index();
            $table->string('city_id', 7)->nullable()->after('barangay_id')->index();
            $table->string('province_id', 5)->nullable()->after('city_id')->index();
            $table->string('region_id', 2)->nullable()->after('province_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['barangay_id']);
            $table->dropIndex(['city_id']);
            $table->dropIndex(['province_id']);
            $table->dropIndex(['region_id']);
            $table->dropColumn(['street', 'barangay_id', 'city_id', 'province_id', 'region_id']);
        });
    }
};
