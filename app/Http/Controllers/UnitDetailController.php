<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UnitDetail;

class UnitDetailController extends Controller
{
    public function index($unit_id)
    {
        $data = UnitDetail::with('unit:id,name')
            ->where('unit_id', $unit_id)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'unit' => $item->unit->name ?? null,
                    'name' => $item->name,
                    'lokasi' => $item->lokasi,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

        return response()->json($data);
    }


    public function getAll()
    {
        $data = UnitDetail::with('unit:id,name')->get()->map(function ($item) {
            return [
                'id' => $item->id,
                'unit' => $item->unit->name ?? null,
                'name' => $item->name,
                'lokasi' => $item->lokasi,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
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

        // Jika admin_unit, unit_id diambil otomatis dari admin
        if ($admin->role === 'admin_unit') {
            $request->merge(['unit_id' => $admin->unit_id]);
        }

        // Jika super_admin, unit_id harus diinput manual
        $rules = [
            'name' => 'required_without:nama',
            'nama' => 'required_without:name',
            'lokasi' => 'required|array',
        ];
        if ($admin->role === 'super_admin') {
            $rules['unit_id'] = 'required|exists:unit,id';
        }

        $request->validate($rules);
        try {
            // Ambil 'name' dari 'name' atau 'nama'
            $name = $request->name ?? $request->nama;
            if (!$name) {
                return response()->json(['message' => 'Field name/nama wajib diisi'], 400);
            }
            $detail = UnitDetail::create([
                'unit_id' => $request->unit_id,
                'name' => $name,
                'lokasi' => $request->lokasi,
            ]);
            return response()->json($detail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $detail = UnitDetail::find($id);
        if (!$detail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }
        $request->validate([
            'name' => 'sometimes|required',
            'lokasi' => 'sometimes|required|array',
        ]);
        try {
            $data = $request->only(['name', 'lokasi']);
            $detail->update($data);
            return response()->json($detail);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $detail = UnitDetail::find($id);
        if (!$detail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }
        try {
            $detail->delete();
            return response()->json(['message' => 'Unit detail deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show($id)
    {
        $detail = UnitDetail::with('unit:id,name')->find($id);
        if (!$detail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 404);
        }
        return response()->json([
            'id' => $detail->id,
            'unit' => $detail->unit->name ?? null,
            'name' => $detail->name,
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
        // if (!$admin || $admin->role !== 'admin_unit') {
        //     return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        // }

        $request->validate([
            'unit_detail_id' => 'required|exists:unit_detail,id',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
        ]);

        // Validasi unit detail milik unit admin
        $unitDetail = \App\Models\UnitDetail::where('id', $request->unit_detail_id)
            //->where('unit_id', $admin->unit_id)
            ->first();
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan atau bukan milik unit admin'], 404);
        }

        // Update pegawai
        $count = \App\Models\MsPegawai::whereIn('id', $request->pegawai_ids)
            ->update(['unit_detail_id_presensi' => $request->unit_detail_id]);

        return response()->json([
            'message' => 'Berhasil assign pegawai ke unit detail',
            'jumlah_pegawai_diupdate' => $count
        ]);
    }
}
