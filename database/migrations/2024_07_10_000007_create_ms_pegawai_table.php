<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawai', function (Blueprint $table) {
            $table->id();
            $table->integer('id_old_pegawai')->nullable();
            $table->integer('id_unit')->nullable();
            $table->integer('id_unit_kerja')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('id_upk')->nullable();
            $table->integer('id_homebase')->nullable();
            $table->integer('id_tipe')->nullable();
            $table->integer('id_user')->nullable();
            $table->integer('id_sync')->nullable();
            $table->string('no_ktp')->nullable();
            $table->string('nama')->nullable();
            $table->string('gelar_depan')->nullable();
            $table->string('gelar_belakang')->nullable();
            $table->string('tmpt_lahir')->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->string('jenis_kelamin', 1)->nullable();
            $table->integer('tinggi')->nullable();
            $table->integer('berat')->nullable();
            $table->string('gol_darah')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kabupaten')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kelurahan')->nullable();
            $table->text('alamat')->nullable();
            $table->string('kode_pos')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('no_telepon')->nullable();
            $table->string('no_whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('shift_detail_id')->nullable();
            $table->unsignedBigInteger('unit_detail_id_presensi');
            $table->timestamps();
            $table->timestamp('last_sync')->nullable();

            $table->foreign('shift_detail_id')->references('id')->on('shift_detail')->onDelete('cascade');
            $table->foreign('unit_detail_id_presensi')->references('id')->on('unit_detail')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai');
    }
};
