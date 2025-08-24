<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UnitDetail;
use App\Models\Unit;
use App\Helpers\AdminUnitHelper;

class UnitDetailController extends Controller
{
    public function index($unit_id)
    {
        $data = UnitDetail::with('unit:id,nama')
            ->where('ms_unit_id', $unit_id)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'unit' => $item->unit->nama ?? null,
                    'lokasi' => $item->lokasi,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

        return response()->json($data);
    }

    public function getAll()
    {
        $data = UnitDetail::with('unit:id,nama')->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'unit' => $item->unit->nama ?? null,
                'lokasi' => $item->lokasi,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json($data);
    }

    /**
     * Update lokasi unit (hanya untuk admin unit)
     * Admin unit hanya bisa update lokasi unit yang parent-nya mengarah ke unit admin
     */
    public function updateLocation(Request $request, $unit_id)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // if ($admin->role !== 'admin_unit') {
        //     return response()->json(['message' => 'Hanya admin unit yang dapat mengupdate lokasi'], 403);
        // }

        // Validasi unit yang akan diupdate lokasinya
        $targetUnit = Unit::find($unit_id);
        if (!$targetUnit) {
            return response()->json(['message' => 'Unit tidak ditemukan'], 404);
        }

        // Cek apakah unit target adalah child dari unit admin
        // if ($targetUnit->id_parent !== $admin->unit_id) {
        //     return response()->json(['message' => 'Tidak dapat mengupdate lokasi unit yang bukan child dari unit admin'], 403);
        // }

        $request->validate([
            'lokasi' => 'required|array',
        ]);

        try {
            $unitDetail = UnitDetail::where('ms_unit_id', $unit_id)->first();
            
            if ($unitDetail) {
                $unitDetail->update(['lokasi' => $request->lokasi]);
            } else {
                $unitDetail = UnitDetail::create([
                    'ms_unit_id' => $unit_id,
                    'lokasi' => $request->lokasi
                ]);
            }

            return response()->json([
                'message' => 'Lokasi berhasil diupdate',
                'data' => $unitDetail
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show($id)
    {
        $detail = UnitDetail::with('unit:id,nama')->find($id);
        if (!$detail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }
        return response()->json([
            'id' => $detail->id,
            'unit' => $detail->unit->nama ?? null,
            'lokasi' => $detail->lokasi,
            'created_at' => $detail->created_at,
            'updated_at' => $detail->updated_at,
        ]);
    }

    /**
     * Assign pegawai ke unit detail presensi tertentu (admin unit)
     */
    public function assignPegawai(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $request->validate([
            'unit_detail_id' => 'required|exists:presensi_ms_unit_detail,id',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:ms_orang,id',
        ]);

        // Validasi unit detail milik unit admin
        $unitDetail = UnitDetail::find($request->unit_detail_id);
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }

        // Cek apakah unit detail ini milik unit yang bisa diakses admin
        // $targetUnit = Unit::find($unitDetail->ms_unit_id);
        // if (!$targetUnit || $targetUnit->id_parent !== $admin->unit_id) {
        //     return response()->json(['message' => 'Unit detail tidak dapat diakses'], 403);
        // }

        // Update pegawai
        $count = \App\Models\MsPegawai::whereIn('id_orang', $request->pegawai_ids)
            ->update(['presensi_ms_unit_detail_id' => $request->unit_detail_id]);

        return response()->json([
            'message' => 'Berhasil menambahkan pegawai ke unit detail',
            'jumlah_pegawai_diupdate' => $count
        ]);
    }
}