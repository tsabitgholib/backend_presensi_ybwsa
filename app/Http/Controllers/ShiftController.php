<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shift;
use App\Models\ShiftDetail;
use App\Helpers\AdminUnitHelper;

class ShiftController extends Controller
{
    // CRUD Shift
    public function index(Request $request)
    {
        $admin = $request->get('admin');
        if ($admin && $admin->role === 'admin_unit') {
            $unitId = $admin->unit_id;
            $query = Shift::with(['unit', 'shiftDetail'])
                ->where('unit_id', $unitId);
        } else {
            $query = Shift::with(['unit', 'shiftDetail']);
        }
        $data = $query->get()->map(function ($shift) {
            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'unit_name' => $shift->unit->name ?? null,
                'created_at' => $shift->created_at,
                'updated_at' => $shift->updated_at,
                'shift_detail' => $shift->shiftDetail
            ];
        });
        return response()->json($data);
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
            'name' => 'required',
        ], $unitValidationRules));

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        try {
            $shift = Shift::create([
                'name' => $request->name,
                'unit_id' => $unitId,
            ]);
            return response()->json($shift);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $shift = Shift::find($id);
        if (!$shift) {
            return response()->json(['message' => 'Shift tidak ditemukan'], 404);
        }
        $request->validate([
            'name' => 'sometimes|required',
        ]);
        try {
            $shift->update($request->only('name'));
            return response()->json($shift);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $shift = Shift::find($id);
        if (!$shift) {
            return response()->json(['message' => 'Shift tidak ditemukan'], 404);
        }
        try {
            $shift->delete();
            return response()->json(['message' => 'Shift deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    // CRUD Shift Detail
    public function storeShiftDetail(Request $request)
    {
        $request->validate([
            'shift_id' => 'required|exists:shift,id',
            'senin_masuk' => 'nullable|date_format:H:i',
            'senin_pulang' => 'nullable|date_format:H:i',
            'selasa_masuk' => 'nullable|date_format:H:i',
            'selasa_pulang' => 'nullable|date_format:H:i',
            'rabu_masuk' => 'nullable|date_format:H:i',
            'rabu_pulang' => 'nullable|date_format:H:i',
            'kamis_masuk' => 'nullable|date_format:H:i',
            'kamis_pulang' => 'nullable|date_format:H:i',
            'jumat_masuk' => 'nullable|date_format:H:i',
            'jumat_pulang' => 'nullable|date_format:H:i',
            'sabtu_masuk' => 'nullable|date_format:H:i',
            'sabtu_pulang' => 'nullable|date_format:H:i',
            'minggu_masuk' => 'nullable|date_format:H:i',
            'minggu_pulang' => 'nullable|date_format:H:i',
            'toleransi_terlambat' => 'nullable|integer|min:0',
            'toleransi_pulang' => 'nullable|integer|min:0',
        ]);
        try {
            $shiftDetail = ShiftDetail::create($request->all());
            return response()->json($shiftDetail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function updateShiftDetail(Request $request, $id)
    {
        $shiftDetail = ShiftDetail::find($id);
        if (!$shiftDetail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan'], 404);
        }
        $request->validate([
            'senin_masuk' => 'nullable|date_format:H:i',
            'senin_pulang' => 'nullable|date_format:H:i',
            'selasa_masuk' => 'nullable|date_format:H:i',
            'selasa_pulang' => 'nullable|date_format:H:i',
            'rabu_masuk' => 'nullable|date_format:H:i',
            'rabu_pulang' => 'nullable|date_format:H:i',
            'kamis_masuk' => 'nullable|date_format:H:i',
            'kamis_pulang' => 'nullable|date_format:H:i',
            'jumat_masuk' => 'nullable|date_format:H:i',
            'jumat_pulang' => 'nullable|date_format:H:i',
            'sabtu_masuk' => 'nullable|date_format:H:i',
            'sabtu_pulang' => 'nullable|date_format:H:i',
            'minggu_masuk' => 'nullable|date_format:H:i',
            'minggu_pulang' => 'nullable|date_format:H:i',
            'toleransi_terlambat' => 'nullable|integer|min:0',
            'toleransi_pulang' => 'nullable|integer|min:0',
        ]);
        try {
            $shiftDetail->update($request->all());
            return response()->json($shiftDetail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroyShiftDetail($id)
    {
        $shiftDetail = ShiftDetail::find($id);
        if (!$shiftDetail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan'], 404);
        }
        try {
            $shiftDetail->delete();
            return response()->json(['message' => 'Shift detail deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function getByUnit($unit_id)
    {
        $shifts = Shift::where('unit_id', $unit_id)->with('shiftDetail')->get();
        return response()->json($shifts);
    }

    public function assignPegawaiToShiftDetail(Request $request)
    {
        $request->validate([
            'shift_detail_id' => 'required|exists:shift_detail,id',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:pegawai,id',
        ]);
        $count = \App\Models\MsPegawai::whereIn('id', $request->pegawai_ids)
            ->update(['shift_detail_id' => $request->shift_detail_id]);
        return response()->json([
            'message' => 'Berhasil Menambahkan Pegawai ke Shift ini',
            'jumlah_pegawai_diupdate' => $count
        ]);
    }

    public function getShiftDetailById($id)
    {
        $shiftDetail = ShiftDetail::with('shift')->find($id);
        if (!$shiftDetail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan'], 404);
        }
        return response()->json($shiftDetail);
    }
}
