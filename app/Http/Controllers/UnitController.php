<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Unit;

class UnitController extends Controller
{
    public function index()
    {
        return response()->json(Unit::with('unitDetails')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:unit,name',
        ]);
        try {
            $unit = Unit::create($request->only('name'));
            return response()->json($unit);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $unit = Unit::find($id);
        if (!$unit) {
            return response()->json(['message' => 'Unit tidak ditemukan'], 404);
        }
        $request->validate([
            'name' => 'sometimes|required|unique:unit,name,' . $id,
        ]);
        try {
            $unit->update($request->only('name'));
            return response()->json($unit);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $unit = Unit::find($id);
        if (!$unit) {
            return response()->json(['message' => 'Unit tidak ditemukan'], 404);
        }
        try {
            $unit->delete();
            return response()->json(['message' => 'Unit deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}