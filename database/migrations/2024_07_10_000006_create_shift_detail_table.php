<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->string('senin_masuk')->nullable();
            $table->string('senin_pulang')->nullable();
            $table->string('selasa_masuk')->nullable();
            $table->string('selasa_pulang')->nullable();
            $table->string('rabu_masuk')->nullable();
            $table->string('rabu_pulang')->nullable();
            $table->string('kamis_masuk')->nullable();
            $table->string('kamis_pulang')->nullable();
            $table->string('jumat_masuk')->nullable();
            $table->string('jumat_pulang')->nullable();
            $table->string('sabtu_masuk')->nullable();
            $table->string('sabtu_pulang')->nullable();
            $table->string('minggu_masuk')->nullable();
            $table->string('minggu_pulang')->nullable();
            $table->integer('toleransi_terlambat')->default(0);
            $table->integer('toleransi_pulang')->default(0);
            $table->timestamps();

            $table->foreign('shift_id')->references('id')->on('shift')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_detail');
    }
}; 