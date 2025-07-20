<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengajuanIzin extends Model
{
    protected $table = 'pengajuan_izin';
    protected $fillable = [
        'pegawai_id', 'izin_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan', 'dokumen', 'status', 'admin_unit_id', 'keterangan_admin'
    ];

    public function pegawai() {
        return $this->belongsTo(MsPegawai::class, 'pegawai_id');
    }
    public function jenis() {
        return $this->belongsTo(Izin::class, 'izin_id');
    }
    public function adminUnit() {
        return $this->belongsTo(Admin::class, 'admin_unit_id');
    }
} 