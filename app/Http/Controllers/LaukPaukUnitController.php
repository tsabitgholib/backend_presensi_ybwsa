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

    public function store(Request $request)
    {
        $request->validate([
            'unit_id' => 'required|exists:unit,id',
            'nominal' => 'required|numeric|min:0',
        ]);
        $data = LaukPaukUnit::create($request->only(['unit_id', 'nominal']));
        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $data = LaukPaukUnit::find($id);
        if (!$data) return response()->json(['message' => 'Data tidak ditemukan'], 404);
        $request->validate([
            'unit_id' => 'sometimes|exists:unit,id',
            'nominal' => 'sometimes|numeric|min:0',
        ]);
        $data->update($request->only(['unit_id', 'nominal']));
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
