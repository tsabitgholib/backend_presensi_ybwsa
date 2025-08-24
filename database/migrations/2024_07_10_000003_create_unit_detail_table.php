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
        Schema::create('presensi_ms_unit_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('ms_unit_id');
            $table->json('lokasi')->nullable();
            $table->timestamps();

            $table->foreign('ms_unit_id')
                  ->references('id')
                  ->on('ms_unit')
                  ->onDelete('cascade'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('presensi_ms_unit_detail');
    }
};
