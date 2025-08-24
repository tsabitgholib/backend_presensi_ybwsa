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
            // Admin unit: ambil unit yang parent-nya mengarah ke unit admin
            $units = Unit::where('id_parent', $admin->unit_id)
                        ->with('children')
                        ->get()
                        ->map(function ($unit) {
                            $unitDetail = UnitDetail::where('ms_unit_id', $unit->id)->first();
                            return [
                                'id' => $unit->id,
                                'nama' => $unit->nama,
                                'level' => $unit->level,
                                'id_parent' => $unit->id_parent,
                                'lokasi' => $unitDetail ? $unitDetail->lokasi : null,
                                //'children' => $unit->children
                            ];
                        });
        } else {
            // Super admin: lihat semua
            $units = Unit::with('children')->get()
                        ->map(function ($unit) {
                            $unitDetail = UnitDetail::where('ms_unit_id', $unit->id)->first();
                            return [
                                'id' => $unit->id,
                                'nama' => $unit->nama,
                                'level' => $unit->level,
                                'id_parent' => $unit->id_parent,
                                'lokasi' => $unitDetail ? $unitDetail->lokasi : null,
                                //'children' => $unit->children
                            ];
                        });
        }

        return response()->json($units);
    }

    // Hapus method store, update, destroy
}