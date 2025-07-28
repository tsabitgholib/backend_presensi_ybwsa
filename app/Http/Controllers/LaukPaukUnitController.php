<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LaukPaukUnit;

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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $unit_id = $admin->unit_id;
        $laukPauk = \App\Models\LaukPaukUnit::where('unit_id', $unit_id)->first();
        if (!$laukPauk) {
            return response()->json(['unit_id' => $unit_id, 'nominal' => 0]);
        }
        return response()->json($laukPauk);
    }

    public function store(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $request->validate([
            'nominal' => 'required|numeric|min:0',
        ]);
        $unit_id = $admin->unit_id;
        $data = \App\Models\LaukPaukUnit::updateOrCreate(
            ['unit_id' => $unit_id],
            ['nominal' => $request->nominal]
        );
        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $request->validate([
            'nominal' => 'required|numeric|min:0',
        ]);
        $unit_id = $admin->unit_id;
        $data = \App\Models\LaukPaukUnit::where('unit_id', $unit_id)->first();
        if (!$data) {
            $data = \App\Models\LaukPaukUnit::create([
                'unit_id' => $unit_id,
                'nominal' => $request->nominal
            ]);
        } else {
            $data->update(['nominal' => $request->nominal]);
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
