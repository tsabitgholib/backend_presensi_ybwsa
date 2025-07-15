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
        Schema::table('ms_pegawai', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id_presensi')->nullable()->after('shift_detail_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ms_pegawai', function (Blueprint $table) {
            $table->dropColumn('unit_id_presensi');
        });
    }
};