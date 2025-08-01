<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('unit_id');
            $table->timestamps();

            $table->foreign('unit_id')->references('id')->on('unit')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift');
    }
}; 