<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shift;
use App\Models\ShiftDetail;

class ShiftController extends Controller
{
    // CRUD Shift
    public function index()
    {
        $data = Shift::with([
            'unitDetail.unit', 
            'shiftDetail'
        ])->get()->map(function ($shift) {
            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'unit_name' => $shift->unitDetail->unit->name ?? null,
                'unit_detail_name' => $shift->unitDetail->name ?? null,
                'created_at' => $shift->created_at,
                'updated_at' => $shift->updated_at,
                'shift_detail' => $shift->shiftDetail
            ];
        });

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
        // Ambil unit_detail_id pertama dari unit admin
        $unitDetail = $admin->unit
            ? $admin->unit->unitDetails()->first()
            : null;
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan untuk admin ini'], 400);
        }
        try {
            $shift = Shift::create([
                'name' => $request->name,
                'unit_detail_id' => $unitDetail->id,
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
        // Ambil unit_detail_id pertama dari unit admin
        $unitDetail = $admin->unit
            ? $admin->unit->unitDetails()->first()
            : null;
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan untuk admin ini'], 400);
        }
        try {
            $data = $request->only(['name']);
            $data['unit_detail_id'] = $unitDetail->id;
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
                'senin_masuk', 'senin_pulang',
                'selasa_masuk', 'selasa_pulang',
                'rabu_masuk', 'rabu_pulang',
                'kamis_masuk', 'kamis_pulang',
                'jumat_masuk', 'jumat_pulang',
                'sabtu_masuk', 'sabtu_pulang',
                'minggu_masuk', 'minggu_pulang',
                'toleransi_terlambat', 'toleransi_pulang'
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
                'senin_masuk', 'senin_pulang',
                'selasa_masuk', 'selasa_pulang',
                'rabu_masuk', 'rabu_pulang',
                'kamis_masuk', 'kamis_pulang',
                'jumat_masuk', 'jumat_pulang',
                'sabtu_masuk', 'sabtu_pulang',
                'minggu_masuk', 'minggu_pulang',
                'toleransi_terlambat', 'toleransi_pulang'
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

    public function getByUnitDetail($unit_detail_id)
    {
        $shifts = Shift::where('unit_detail_id', $unit_detail_id)->with('shiftDetail')->get();
        return response()->json($shifts);
    }
} 