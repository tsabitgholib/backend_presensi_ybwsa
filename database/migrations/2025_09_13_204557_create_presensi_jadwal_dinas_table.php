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
        Schema::create('presensi_jadwal_dinas', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('keterangan');
            $table->json('pegawai_ids'); // Array ID pegawai yang akan dinas
            $table->unsignedInteger('unit_id'); // Unit yang mengatur jadwal dinas
            $table->unsignedInteger('created_by'); // Admin yang membuat jadwal
            $table->boolean('is_active')->default(true); // Status aktif/tidak aktif
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('unit_id')->references('id')->on('ms_unit')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('admin')->onDelete('cascade');
            
            // Index untuk performa query
            $table->index(['tanggal_mulai', 'tanggal_selesai']);
            $table->index(['unit_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presensi_jadwal_dinas');
    }
};
