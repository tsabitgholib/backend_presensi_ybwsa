<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pengajuan_izin', function (Blueprint $table) {
            $table->id();
            $table->integer('pegawai_id');
            $table->unsignedBigInteger('izin_id');
            $table->unsignedBigInteger('admin_unit_id')->nullable();

            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('alasan');
            $table->string('dokumen')->nullable();
            $table->enum('status', ['pending', 'diterima', 'ditolak'])->default('pending');
            $table->text('keterangan_admin')->nullable();
            $table->timestamps();

            $table->foreign('pegawai_id')->references('id')->on('pegawai')->onDelete('cascade');
            $table->foreign('izin_id')->references('id')->on('izin')->onDelete('cascade');
            $table->foreign('admin_unit_id')->references('id')->on('admin')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('pengajuan_izin');
    }
};