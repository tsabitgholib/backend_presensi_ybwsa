<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lauk_pauk_unit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unit_id');
            $table->bigInteger('nominal');
            $table->timestamps();

            $table->foreign('unit_id')->references('id')->on('unit')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lauk_pauk_unit');
    }
};
