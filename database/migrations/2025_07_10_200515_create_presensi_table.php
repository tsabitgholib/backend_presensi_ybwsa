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
        Schema::create('presensi', function (Blueprint $table) {
            $table->id();
            $table->string('no_ktp');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('shift_detail_id');
            $table->timestamp('waktu');
            $table->string('status');
            $table->json('lokasi');
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('no_ktp')->references('no_ktp')->on('ms_pegawai')->onDelete('cascade');
            $table->foreign('shift_id')->references('id')->on('shift')->onDelete('cascade');
            $table->foreign('shift_detail_id')->references('id')->on('shift_detail')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presensi');
    }
};