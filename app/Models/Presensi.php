<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Status Presensi yang Standar
 */
class Presensi extends Model
{
    // Konstanta untuk status masuk
    const STATUS_MASUK_ABSEN_MASUK = 'absen_masuk';
    const STATUS_MASUK_TERLAMBAT = 'terlambat';
    const STATUS_MASUK_TIDAK_ABSEN_MASUK = 'tidak_absen_masuk';
    const STATUS_MASUK_TIDAK_HADIR = 'tidak_hadir';
    const STATUS_MASUK_IZIN = 'izin';
    const STATUS_MASUK_SAKIT = 'sakit';
    const STATUS_MASUK_CUTI = 'cuti';

    // Konstanta untuk status pulang
    const STATUS_PULANG_ABSEN_PULANG = 'absen_pulang';
    const STATUS_PULANG_PULANG_AWAL = 'pulang_awal';
    const STATUS_PULANG_TIDAK_ABSEN_PULANG = 'tidak_absen_pulang';
    const STATUS_PULANG_TIDAK_HADIR = 'tidak_hadir';
    const STATUS_PULANG_IZIN = 'izin';
    const STATUS_PULANG_SAKIT = 'sakit';
    const STATUS_PULANG_CUTI = 'cuti';

    // Konstanta untuk status presensi
    const STATUS_PRESENSI_HADIR = 'hadir';
    const STATUS_PRESENSI_TIDAK_HADIR = 'tidak_hadir';
    const STATUS_PRESENSI_IZIN = 'izin';
    const STATUS_PRESENSI_SAKIT = 'sakit';
    const STATUS_PRESENSI_CUTI = 'cuti';
    const STATUS_PRESENSI_DINAS = 'dinas';

    // Array status yang valid
    public static $validStatusMasuk = [
        self::STATUS_MASUK_ABSEN_MASUK,
        self::STATUS_MASUK_TERLAMBAT,
        self::STATUS_MASUK_TIDAK_ABSEN_MASUK,
        self::STATUS_MASUK_TIDAK_HADIR,
        self::STATUS_MASUK_IZIN,
        self::STATUS_MASUK_SAKIT,
        self::STATUS_MASUK_CUTI,
    ];

    public static $validStatusPulang = [
        self::STATUS_PULANG_ABSEN_PULANG,
        self::STATUS_PULANG_PULANG_AWAL,
        self::STATUS_PULANG_TIDAK_ABSEN_PULANG,
        self::STATUS_PULANG_TIDAK_HADIR,
        self::STATUS_PULANG_IZIN,
        self::STATUS_PULANG_SAKIT,
        self::STATUS_PULANG_CUTI,
    ];

    public static $validStatusPresensi = [
        self::STATUS_PRESENSI_HADIR,
        self::STATUS_PRESENSI_TIDAK_HADIR,
        self::STATUS_PRESENSI_IZIN,
        self::STATUS_PRESENSI_SAKIT,
        self::STATUS_PRESENSI_CUTI,
        self::STATUS_PRESENSI_DINAS,
    ];

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
        'keterangan_pulang',
        'overtime'
    ];
    protected $casts = [
        'lokasi' => 'array',
        'waktu' => 'datetime',
        'lokasi_masuk' => 'array',
        'lokasi_pulang' => 'array',
        'waktu_masuk' => 'datetime:Y-m-d H:i:s',
        'waktu_pulang' => 'datetime:Y-m-d H:i:s',
    ];
    

    public function pegawai()
    {
        return $this->belongsTo(MsOrang::class, 'no_ktp', 'no_ktp');
    }
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
    public function shiftDetail()
    {
        return $this->belongsTo(ShiftDetail::class);
    }

    /**
     * Validasi status masuk
     */
    public static function isValidStatusMasuk($status)
    {
        return in_array($status, self::$validStatusMasuk);
    }

    /**
     * Validasi status pulang
     */
    public static function isValidStatusPulang($status)
    {
        return in_array($status, self::$validStatusPulang);
    }

    /**
     * Validasi status presensi
     */
    public static function isValidStatusPresensi($status)
    {
        return in_array($status, self::$validStatusPresensi);
    }

    /**
     * Get status yang dianggap hadir
     */
    public static function getHadirStatuses()
    {
        return [
            self::STATUS_MASUK_ABSEN_MASUK,
            self::STATUS_MASUK_TERLAMBAT,
            self::STATUS_PULANG_ABSEN_PULANG,
            self::STATUS_PULANG_PULANG_AWAL,
        ];
    }

    /**
     * Get status khusus (izin, sakit, cuti, dinas)
     */
    public static function getSpecialStatuses()
    {
        return [
            self::STATUS_MASUK_IZIN,
            self::STATUS_MASUK_SAKIT,
            self::STATUS_MASUK_CUTI,
            self::STATUS_PULANG_IZIN,
            self::STATUS_PULANG_SAKIT,
            self::STATUS_PULANG_CUTI,
            self::STATUS_PRESENSI_DINAS,
        ];
    }
}
