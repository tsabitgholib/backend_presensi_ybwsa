<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presensi extends Model
{
    protected $table = 'presensi';
    protected $fillable = [
        'no_ktp',
        'shift_id',
        'shift_detail_id',
        'waktu',
        'status',
        'status_presensi',
        'lokasi',
        'keterangan',
        // Kolom baru untuk presensi masuk dan pulang
        'waktu_masuk',
        'waktu_pulang',
        'status_masuk',
        'status_pulang',
        'lokasi_masuk',
        'lokasi_pulang',
        'keterangan_masuk',
        'keterangan_pulang'
    ];
    protected $casts = [
        'lokasi' => 'array',
        'waktu' => 'datetime',
        // Cast untuk kolom baru
        'lokasi_masuk' => 'array',
        'lokasi_pulang' => 'array',
        'waktu_masuk' => 'datetime',
        'waktu_pulang' => 'datetime',
    ];

    public function pegawai()
    {
        return $this->belongsTo(MsPegawai::class, 'no_ktp', 'no_ktp');
    }
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
    public function shiftDetail()
    {
        return $this->belongsTo(ShiftDetail::class);
    }
}
