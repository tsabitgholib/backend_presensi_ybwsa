<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hari_libur', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unit_detail_id');
            $table->date('tanggal');
            $table->string('keterangan');
            $table->unsignedBigInteger('admin_unit_id'); // Admin yang mengatur hari libur
            $table->timestamps();

            $table->foreign('unit_detail_id')->references('id')->on('unit_detail')->onDelete('cascade');
            $table->foreign('admin_unit_id')->references('id')->on('admin')->onDelete('cascade');

            // Pastikan tidak ada duplikasi hari libur untuk unit detail yang sama
            $table->unique(['unit_detail_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hari_libur');
    }
};
