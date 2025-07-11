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
        $request->validate([
            'unit_id' => 'required|exists:unit,id',
  
            'name' => 'required_without:nama',
            'nama' => 'required_without:name',
            'lokasi' => 'required|array',
        ]);
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
} 