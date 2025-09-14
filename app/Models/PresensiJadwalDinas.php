<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PresensiJadwalDinas extends Model
{
    use HasFactory;

    protected $table = 'presensi_jadwal_dinas';

    protected $fillable = [
        'tanggal_mulai',
        'tanggal_selesai',
        'keterangan',
        'pegawai_ids',
        'unit_id',
        'created_by',
        'is_active'
    ];

    protected $casts = [
        'tanggal_mulai' => 'date',
        'tanggal_selesai' => 'date',
        'pegawai_ids' => 'array',
        'is_active' => 'boolean'
    ];

    /**
     * Relasi ke Unit
     */
    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * Relasi ke Admin yang membuat jadwal
     */
    public function createdBy()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Scope untuk jadwal aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope untuk jadwal dalam rentang tanggal tertentu
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('tanggal_mulai', [$startDate, $endDate])
              ->orWhereBetween('tanggal_selesai', [$startDate, $endDate])
              ->orWhere(function ($q2) use ($startDate, $endDate) {
                  $q2->where('tanggal_mulai', '<=', $startDate)
                     ->where('tanggal_selesai', '>=', $endDate);
              });
        });
    }

    /**
     * Cek apakah tanggal tertentu termasuk dalam jadwal dinas
     */
    public function isDateInRange($date)
    {
        $checkDate = Carbon::parse($date);
        return $checkDate->between($this->tanggal_mulai, $this->tanggal_selesai);
    }

    /**
     * Cek apakah pegawai tertentu termasuk dalam jadwal dinas
     */
    public function hasPegawai($pegawaiId)
    {
        return in_array($pegawaiId, $this->pegawai_ids ?? []);
    }

    /**
     * Ambil jadwal dinas untuk pegawai pada tanggal tertentu
     */
    public static function getJadwalDinasForPegawai($pegawaiId, $date)
    {
        return self::active()
            ->whereJsonContains('pegawai_ids', $pegawaiId)
            ->where('tanggal_mulai', '<=', $date)
            ->where('tanggal_selesai', '>=', $date)
            ->first();
    }
}
