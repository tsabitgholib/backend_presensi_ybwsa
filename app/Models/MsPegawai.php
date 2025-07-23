<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MsPegawai extends Model
{
    protected $table = 'ms_pegawai';
    protected $fillable = [
        'id_old_pegawai',
        'id_unit',
        'id_unit_kerja',
        'unit_id',
        'id_upk',
        'id_homebase',
        'id_tipe',
        'id_user',
        'id_sync',
        'no_ktp',
        'nama_depan',
        'nama_tengah',
        'nama_belakang',
        'gelar_depan',
        'gelar_belakang',
        'tmpt_lahir',
        'tgl_lahir',
        'jenis_kelamin',
        'tinggi',
        'berat',
        'gol_darah',
        'provinsi',
        'kabupaten',
        'kecamatan',
        'kelurahan',
        'alamat',
        'kode_pos',
        'no_hp',
        'no_telepon',
        'no_whatsapp',
        'email',
        'jabatan',
        'password',
        'shift_detail_id',
        'unit_detail_id_presensi',
        'last_sync'
    ];

    public function shiftDetail()
    {
        return $this->belongsTo(\App\Models\ShiftDetail::class);
    }

    public function unit()
    {
        return $this->belongsTo(\App\Models\Unit::class);
    }

    public function unitDetail()
    {
        return $this->belongsTo(\App\Models\UnitDetail::class);
    }

    public function unitDetailPresensi()
    {
        return $this->belongsTo(\App\Models\UnitDetail::class, 'unit_detail_id_presensi');
    }
}
