<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MsOrang extends Model
{
protected $table = 'ms_orang';
    protected $primaryKey = 'id';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'id_sync',
        'nip_unit',
        'no_ktp',
        'no_kk',
        'nama',
        'gelar_depan',
        'gelar_belakang',
        'tmpt_lahir',
        'tgl_lahir',
        'tinggi',
        'berat',
        'gol_darah',
        'jenis_kelamin',
        'provinsi_ktp',
        'kabupaten_ktp',
        'kecamatan_ktp',
        'kelurahan_ktp',
        'alamat_ktp',
        'kode_pos_ktp',
        'provinsi',
        'kabupaten',
        'kecamatan',
        'kelurahan',
        'alamat',
        'no_hp',
        'email',
        'foto',
        'status_nikah',
        'tipe',
        'created_at',
        'modified_at',
        'last_sync',
    ];

    public function shiftDetail()
    {
        return $this->hasOneThrough(
            \App\Models\ShiftDetail::class, // target akhir
            \App\Models\MsPegawai::class,   // tabel perantara
            'id_orang',                     // FK di MsPegawai -> ms_orang.id
            'id',                           // PK di ShiftDetail
            'id',                           // PK di MsOrang
            'presensi_shift_detail_id'      // FK di MsPegawai -> shift_detail.id
        );
    }

    public function unit()
    {
        return $this->belongsTo(\App\Models\Unit::class);
    }

    public function unitDetailPresensi()
    {
        return $this->hasOneThrough(
            \App\Models\UnitDetail::class,
            \App\Models\MsPegawai::class,
            'id_orang',                     // FK di ms_pegawai -> ms_orang.id
            'id',                           // PK di unit_detail
            'id',                           // PK di ms_orang
            'presensi_ms_unit_detail_id'    // FK di ms_pegawai -> unit_detail.id
        );
    }

    public function presensi()
    {
        return $this->hasMany(Presensi::class, 'no_ktp', 'no_ktp');
    }
    
    public function pegawai()
    {
        return $this->hasOne(MsPegawai::class, 'id_orang');
    }
}