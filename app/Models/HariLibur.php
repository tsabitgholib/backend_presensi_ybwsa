<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HariLibur extends Model
{
    protected $table = 'hari_libur';
    protected $fillable = [
        'unit_detail_id',
        'tanggal',
        'keterangan',
        'admin_unit_id'
    ];
    protected $casts = [
        'tanggal' => 'date',
    ];

    public function unitDetail()
    {
        return $this->belongsTo(UnitDetail::class, 'ms_unit_id');
    }

    public function adminUnit()
    {
        return $this->belongsTo(Admin::class, 'admin_unit_id');
    }

    /**
     * Cek apakah tanggal tertentu adalah hari libur untuk unit detail tertentu
     */
    public static function isHariLibur($unitDetailId, $tanggal)
    {
        return self::where('unit_detail_id', $unitDetailId)
            ->whereDate('tanggal', $tanggal)
            ->exists();
    }

    /**
     * Ambil data hari libur untuk unit detail tertentu
     */
    public static function getHariLiburByUnitDetail($unitDetailId, $bulan = null, $tahun = null)
    {
        $query = self::where('unit_detail_id', $unitDetailId);

        if ($bulan && $tahun) {
            $query->whereYear('tanggal', $tahun)
                ->whereMonth('tanggal', $bulan);
        }

        return $query->orderBy('tanggal')->get();
    }
}
