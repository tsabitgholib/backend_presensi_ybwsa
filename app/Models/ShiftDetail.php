<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftDetail extends Model
{
    protected $table = 'shift_detail';
    protected $fillable = [
        'shift_id',
        'senin_masuk', 'senin_pulang',
        'selasa_masuk', 'selasa_pulang',
        'rabu_masuk', 'rabu_pulang',
        'kamis_masuk', 'kamis_pulang',
        'jumat_masuk', 'jumat_pulang',
        'sabtu_masuk', 'sabtu_pulang',
        'minggu_masuk', 'minggu_pulang',
        'toleransi_terlambat', 'toleransi_pulang'
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
} 