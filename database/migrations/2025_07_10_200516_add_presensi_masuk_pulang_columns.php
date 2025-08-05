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
        Schema::table('presensi', function (Blueprint $table) {
            $table->timestamp('waktu_masuk')->nullable();
            $table->timestamp('waktu_pulang')->nullable();
            $table->string('status_masuk')->nullable();
            $table->string('status_pulang')->nullable();
            $table->json('lokasi_masuk')->nullable();
            $table->json('lokasi_pulang')->nullable();
            $table->string('keterangan_masuk')->nullable();
            $table->string('keterangan_pulang')->nullable();
        });        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('presensi', function (Blueprint $table) {
            $table->dropColumn([
                'waktu_masuk',
                'waktu_pulang', 
                'status_masuk',
                'status_pulang',
                'lokasi_masuk',
                'lokasi_pulang',
                'keterangan_masuk',
                'keterangan_pulang'
            ]);
        });
    }
}; 