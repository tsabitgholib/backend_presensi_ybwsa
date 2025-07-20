<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengajuanSakit extends Model
{
    protected $table = 'pengajuan_sakit';
    protected $fillable = [
        'pegawai_id', 'sakit_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan', 'dokumen', 'status', 'admin_unit_id', 'keterangan_admin'
    ];

    public function pegawai() {
        return $this->belongsTo(MsPegawai::class, 'pegawai_id');
    }
    public function jenis() {
        return $this->belongsTo(Sakit::class, 'sakit_id');
    }
    public function adminUnit() {
        return $this->belongsTo(Admin::class, 'admin_unit_id');
    }
} 