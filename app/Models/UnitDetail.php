<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitDetail extends Model
{
    use HasFactory;

    protected $table = 'unit_detail';
    protected $fillable = ['unit_id', 'name', 'lokasi'];
    protected $casts = [
        'lokasi' => 'array',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function pegawais()
    {
        return $this->hasMany(MsPegawai::class, 'unit_detail_id_presensi');
    }

    public function hariLibur()
    {
        return $this->hasMany(HariLibur::class);
    }
}
