<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LaukPaukUnit;
use App\Helpers\AdminUnitHelper;

class LaukPaukUnitController extends Controller
{
    public function index()
    {
        return response()->json(LaukPaukUnit::with('unit')->get());
    }

    public function show($id)
    {
        $data = LaukPaukUnit::with('unit')->find($id);
        if (!$data) return response()->json(['message' => 'Data tidak ditemukan'], 404);
        return response()->json($data);
    }

    public function showByAdminUnit(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $laukPauk = \App\Models\LaukPaukUnit::where('unit_id', $unitId)->first();
        // if (!$laukPauk) {
        //     return response()->json(['unit_id' => $unitId, 'nominal' => 0]);
        // }
        return response()->json($laukPauk);
    }

    public function store(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitValidationRules = AdminUnitHelper::getUnitIdValidationRules($request);
        
        $request->validate(array_merge([
            'nominal' => 'required|numeric|min:0',
            // Validasi untuk kolom penalty
            'pot_izin_pribadi' => 'nullable|numeric|min:0',
            'pot_tanpa_izin' => 'nullable|numeric|min:0',
            'pot_sakit' => 'nullable|numeric|min:0',
            'pot_pulang_awal_beralasan' => 'nullable|numeric|min:0',
            'pot_pulang_awal_tanpa_beralasan' => 'nullable|numeric|min:0',
            'pot_terlambat_0806_0900' => 'nullable|numeric|min:0',
            'pot_terlambat_0901_1000' => 'nullable|numeric|min:0',
            'pot_terlambat_setelah_1000' => 'nullable|numeric|min:0',
        ], $unitValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $data = \App\Models\LaukPaukUnit::updateOrCreate(
            ['unit_id' => $unitId],
            [
                'nominal' => $request->nominal,
                'pot_izin_pribadi' => $request->pot_izin_pribadi ?? 50000,
                'pot_tanpa_izin' => $request->pot_tanpa_izin ?? 100000,
                'pot_sakit' => $request->pot_sakit ?? 10000,
                'pot_pulang_awal_beralasan' => $request->pot_pulang_awal_beralasan ?? 20000,
                'pot_pulang_awal_tanpa_beralasan' => $request->pot_pulang_awal_tanpa_beralasan ?? 30000,
                'pot_terlambat_0806_0900' => $request->pot_terlambat_0806_0900 ?? 20000,
                'pot_terlambat_0901_1000' => $request->pot_terlambat_0901_1000 ?? 30000,
                'pot_terlambat_setelah_1000' => $request->pot_terlambat_setelah_1000 ?? 40000,
            ]
        );
        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get validation rules using helper
        $unitValidationRules = AdminUnitHelper::getUnitIdValidationRules($request);
        
        $request->validate(array_merge([
            'nominal' => 'required|numeric|min:0',
            // Validasi untuk kolom penalty
            'pot_izin_pribadi' => 'nullable|numeric|min:0',
            'pot_tanpa_izin' => 'nullable|numeric|min:0',
            'pot_sakit' => 'nullable|numeric|min:0',
            'pot_pulang_awal_beralasan' => 'nullable|numeric|min:0',
            'pot_pulang_awal_tanpa_beralasan' => 'nullable|numeric|min:0',
            'pot_terlambat_0806_0900' => 'nullable|numeric|min:0',
            'pot_terlambat_0901_1000' => 'nullable|numeric|min:0',
            'pot_terlambat_setelah_1000' => 'nullable|numeric|min:0',
        ], $unitValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $data = \App\Models\LaukPaukUnit::where('unit_id', $unitId)->first();
        if (!$data) {
            $data = \App\Models\LaukPaukUnit::create([
                'unit_id' => $unitId,
                'nominal' => $request->nominal,
                'pot_izin_pribadi' => $request->pot_izin_pribadi ?? 50000,
                'pot_tanpa_izin' => $request->pot_tanpa_izin ?? 100000,
                'pot_sakit' => $request->pot_sakit ?? 10000,
                'pot_pulang_awal_beralasan' => $request->pot_pulang_awal_beralasan ?? 20000,
                'pot_pulang_awal_tanpa_beralasan' => $request->pot_pulang_awal_tanpa_beralasan ?? 30000,
                'pot_terlambat_0806_0900' => $request->pot_terlambat_0806_0900 ?? 20000,
                'pot_terlambat_0901_1000' => $request->pot_terlambat_0901_1000 ?? 30000,
                'pot_terlambat_setelah_1000' => $request->pot_terlambat_setelah_1000 ?? 40000,
            ]);
        } else {
            $data->update([
                'nominal' => $request->nominal,
                'pot_izin_pribadi' => $request->pot_izin_pribadi ?? 50000,
                'pot_tanpa_izin' => $request->pot_tanpa_izin ?? 100000,
                'pot_sakit' => $request->pot_sakit ?? 10000,
                'pot_pulang_awal_beralasan' => $request->pot_pulang_awal_beralasan ?? 20000,
                'pot_pulang_awal_tanpa_beralasan' => $request->pot_pulang_awal_tanpa_beralasan ?? 30000,
                'pot_terlambat_0806_0900' => $request->pot_terlambat_0806_0900 ?? 20000,
                'pot_terlambat_0901_1000' => $request->pot_terlambat_0901_1000 ?? 30000,
                'pot_terlambat_setelah_1000' => $request->pot_terlambat_setelah_1000 ?? 40000,
            ]);
        }
        return response()->json($data);
    }

    public function destroy($id)
    {
        $data = LaukPaukUnit::find($id);
        if (!$data) return response()->json(['message' => 'Data tidak ditemukan'], 404);
        $data->delete();
        return response()->json(['message' => 'Berhasil dihapus']);
    }
}