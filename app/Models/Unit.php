<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Admin;
use App\Models\UnitDetail;
use App\Models\Shift;

class Unit extends Model
{
    use HasFactory;

protected $table = 'ms_unit'; // nama tabel di DB

    protected $fillable = [
        'id_sync',
        'kode_surat',
        'id_parent',
        'id_jabatan_pimpinan',
        'nama',
        'alias',
        'level',
        'lvl_surat',
        'lvl',
        'presensi_ms_unit_detail_id'
    ];

    /**
     * Relasi ke parent unit
     */
    public function parent()
    {
        return $this->belongsTo(Unit::class, 'id_parent');
    }

    /**
     * Relasi ke children unit
     */
    public function children()
    {
        return $this->hasMany(Unit::class, 'id_parent');
    }

    /**
     * Ambil semua children secara rekursif
     */
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    /**
     * Scope untuk ambil hanya root unit (tanpa parent)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('id_parent');
    }

    public function admins()
    {
        return $this->hasMany(Admin::class);
    }

    public function unitDetails()
    {
        return $this->hasMany(UnitDetail::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function getRootParentId()
{
    $unit = $this;
    while ($unit->parent) {
        $unit = $unit->parent;
    }
    return $unit->id;
}

}