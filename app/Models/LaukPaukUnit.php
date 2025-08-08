<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaukPaukUnit extends Model
{
    protected $table = 'lauk_pauk_unit';
    protected $fillable = [
        'unit_id', 
        'nominal',
        // Kolom penalty untuk potongan pelanggaran
        'pot_izin_pribadi',
        'pot_tanpa_izin',
        'pot_sakit',
        'pot_pulang_awal_beralasan',
        'pot_pulang_awal_tanpa_beralasan',
        'pot_terlambat_0806_0900',
        'pot_terlambat_0901_1000',
        'pot_terlambat_setelah_1000'
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}