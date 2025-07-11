<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presensi extends Model
{
    protected $table = 'presensi';
    protected $fillable = [
        'no_ktp', 'shift_id', 'shift_detail_id', 'waktu', 'status', 'lokasi', 'keterangan'
    ];
    protected $casts = [
        'lokasi' => 'array',
        'waktu' => 'datetime',
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
