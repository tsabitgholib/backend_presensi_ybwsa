<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Admin;
use App\Models\Unit;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function index()
    {
        return response()->json(Admin::with('unit')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:admin,email',
            'password' => 'required|min:6',
            'role' => 'required|in:super_admin,admin_unit',
            'unit_id' => 'nullable|exists:unit,id',
            'status' => 'required|in:aktif,nonaktif',
        ]);
        try {
            $admin = Admin::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'unit_id' => $request->unit_id,
                'status' => $request->status,
            ]);
            return response()->json($admin);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $admin = Admin::find($id);
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }
        $request->validate([
            'name' => 'sometimes|required',
            'email' => 'sometimes|required|email|unique:admin,email,' . $id,
            'password' => 'nullable|min:6',
            'role' => 'sometimes|required|in:super_admin,admin_unit',
            'unit_id' => 'nullable|exists:unit,id',
            'status' => 'sometimes|required|in:aktif,nonaktif',
        ]);
        try {
            $data = $request->only(['name', 'email', 'role', 'unit_id', 'status']);
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $admin->update($data);
            return response()->json($admin);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $admin = Admin::find($id);
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 404);
        }
        try {
            $admin->delete();
            return response()->json(['message' => 'Admin deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
} 