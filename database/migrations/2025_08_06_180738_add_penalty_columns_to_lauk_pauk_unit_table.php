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
        Schema::table('lauk_pauk_unit', function (Blueprint $table) {
            // Kolom potongan untuk jenis pelanggaran
            $table->bigInteger('pot_izin_pribadi')->default(50000)->comment('Potongan untuk izin tidak masuk kerja (Pribadi)');
            $table->bigInteger('pot_tanpa_izin')->default(100000)->comment('Potongan untuk tidak masuk kerja tanpa izin');
            $table->bigInteger('pot_sakit')->default(10000)->comment('Potongan untuk izin sakit');
            $table->bigInteger('pot_pulang_awal_beralasan')->default(20000)->comment('Potongan untuk pulang awal dengan alasan');
            $table->bigInteger('pot_pulang_awal_tanpa_beralasan')->default(30000)->comment('Potongan untuk pulang awal tanpa alasan');
            $table->bigInteger('pot_terlambat_0806_0900')->default(20000)->comment('Potongan untuk terlambat 08.06-09.00 (relatif terhadap shift)');
            $table->bigInteger('pot_terlambat_0901_1000')->default(30000)->comment('Potongan untuk terlambat 09.01-10.00 (relatif terhadap shift)');
            $table->bigInteger('pot_terlambat_setelah_1000')->default(40000)->comment('Potongan untuk terlambat setelah 10.00 (relatif terhadap shift)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lauk_pauk_unit', function (Blueprint $table) {
            $table->dropColumn([
                'pot_izin_pribadi',
                'pot_tanpa_izin', 
                'pot_sakit',
                'pot_pulang_awal_beralasan',
                'pot_pulang_awal_tanpa_beralasan',
                'pot_terlambat_0806_0900',
                'pot_terlambat_0901_1000',
                'pot_terlambat_setelah_1000'
            ]);
        });
    }
};
