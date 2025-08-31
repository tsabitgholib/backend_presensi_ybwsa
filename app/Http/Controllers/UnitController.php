<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Unit;
use App\Models\UnitDetail;
use App\Helpers\AdminUnitHelper;

class UnitController extends Controller
{
    public function index()
    {
        // Get semua unit dengan tree structure
        return response()->json(Unit::with('children.children')->get());
    }

    /**
     * Get unit dengan lokasi untuk admin unit
     * Admin unit hanya bisa lihat unit yang parent-nya mengarah ke unit admin tersebut
     */
    public function getUnitsWithLocation(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        if ($admin->role === 'admin_unit') {
            // Ambil unit admin
            $rootUnit = Unit::with('childrenRecursive')->find($admin->unit_id);

            if (!$rootUnit) {
                return response()->json(['message' => 'Unit admin tidak ditemukan'], 404);
            }

            // Flatten rekursif
        $units = collect([$rootUnit])   // tambahkan parent (unit admin)
            ->merge($this->flattenUnits($rootUnit->childrenRecursive))
            ->map(function ($unit) {
                $unitDetail = $unit->unitDetails->first();
                return [
                    'id' => $unit->id,
                    'nama' => $unit->nama,
                    'level' => $unit->level,
                    'id_parent' => $unit->id_parent,
                    'lokasi' => $unitDetail ? $unitDetail->lokasi : null,
                    'lokasi2' => $unitDetail ? $unitDetail->lokasi2 : null,
                    'lokasi3' => $unitDetail ? $unitDetail->lokasi3 : null,
                ];
            });
        } else {
            // Super admin: semua unit (beserta anak2nya)
            $units = Unit::with('childrenRecursive', 'unitDetails')->get()
                ->map(function ($unit) {
                    $unitDetail = $unit->unitDetails->first();
                    return [
                        'id' => $unit->id,
                        'nama' => $unit->nama,
                        'level' => $unit->level,
                        'id_parent' => $unit->id_parent,
                        'lokasi' => $unitDetail ? $unitDetail->lokasi : null,
                        'lokasi2' => $unitDetail ? $unitDetail->lokasi2 : null,
                        'lokasi3' => $unitDetail ? $unitDetail->lokasi3 : null,
                    ];
                });
        }

        return response()->json($units);
    }

    /**
     * Helper flattening tree jadi list
     */
    private function flattenUnits($units)
    {
        $result = collect();

        foreach ($units as $unit) {
            $result->push($unit);

            if ($unit->childrenRecursive->isNotEmpty()) {
                $result = $result->merge($this->flattenUnits($unit->childrenRecursive));
            }
        }

        return $result;
    }




    // Hapus method store, update, destroy
}