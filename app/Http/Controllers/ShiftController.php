<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shift;
use App\Models\ShiftDetail;

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
        })->groupBy('unit_name');
        return response()->json($data);
    }



    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
        ]);
        // Ambil admin yang sedang login
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }
        // Ambil unit_id dari admin
        $unitId = $admin->unit_id;
        if (!$unitId) {
            return response()->json(['message' => 'Unit tidak ditemukan untuk admin ini'], 400);
        }
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
        // Ambil admin yang sedang login
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }
        // Ambil unit_id dari admin
        $unitId = $admin->unit_id;
        if (!$unitId) {
            return response()->json(['message' => 'Unit tidak ditemukan untuk admin ini'], 400);
        }
        try {
            $data = $request->only(['name']);
            $data['unit_id'] = $unitId;
            $shift->update($data);
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

    // CRUD ShiftDetail (satu baris per shift)
    public function storeDetail(Request $request)
    {
        $request->validate([
            'shift_id' => 'required|exists:shift,id',
            'toleransi_terlambat' => 'required|integer',
            'toleransi_pulang' => 'required|integer',
        ]);
        try {
            $data = $request->only([
                'shift_id',
                'senin_masuk',
                'senin_pulang',
                'selasa_masuk',
                'selasa_pulang',
                'rabu_masuk',
                'rabu_pulang',
                'kamis_masuk',
                'kamis_pulang',
                'jumat_masuk',
                'jumat_pulang',
                'sabtu_masuk',
                'sabtu_pulang',
                'minggu_masuk',
                'minggu_pulang',
                'toleransi_terlambat',
                'toleransi_pulang'
            ]);
            $detail = ShiftDetail::create($data);
            return response()->json($detail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function updateDetail(Request $request, $id)
    {
        $detail = ShiftDetail::find($id);
        if (!$detail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan'], 404);
        }
        $request->validate([
            'toleransi_terlambat' => 'sometimes|required|integer',
            'toleransi_pulang' => 'sometimes|required|integer',
        ]);
        try {
            $data = $request->only([
                'senin_masuk',
                'senin_pulang',
                'selasa_masuk',
                'selasa_pulang',
                'rabu_masuk',
                'rabu_pulang',
                'kamis_masuk',
                'kamis_pulang',
                'jumat_masuk',
                'jumat_pulang',
                'sabtu_masuk',
                'sabtu_pulang',
                'minggu_masuk',
                'minggu_pulang',
                'toleransi_terlambat',
                'toleransi_pulang'
            ]);
            $detail->update($data);
            return response()->json($detail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroyDetail($id)
    {
        $detail = ShiftDetail::find($id);
        if (!$detail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan'], 404);
        }
        try {
            $detail->delete();
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
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
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
