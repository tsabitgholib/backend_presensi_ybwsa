<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitDetail extends Model
{
    use HasFactory;

    protected $table = 'presensi_ms_unit_detail';
    protected $fillable = ['ms_unit_id', 'lokasi'];
    protected $casts = [
        'lokasi' => 'array',
        'lokasi2' => 'array',
        'lokasi3' => 'array',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'ms_unit_id', 'id');
    }

    public function pegawais()
    {
        return $this->hasMany(MsPegawai::class, 'presensi_ms_unit_detail_id');
    }

    public function pegawaisPresensi()
    {
        return $this->hasMany(MsPegawai::class, 'presensi_ms_unit_detail_id');
    }

    public function hariLibur()
    {
        return $this->hasMany(HariLibur::class);
    }
}