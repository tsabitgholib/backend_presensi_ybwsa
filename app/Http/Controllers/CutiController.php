<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cuti;

class CutiController extends Controller
{
    private function authorizeSuperAdmin($request) {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'super_admin') {
            abort(403, 'Hanya super admin yang boleh mengakses.');
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Cuti::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $cuti = Cuti::create(['jenis' => $request->jenis]);
        return response()->json($cuti);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $cuti = Cuti::findOrFail($id);
        return response()->json($cuti);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $cuti = Cuti::findOrFail($id);
        $cuti->update(['jenis' => $request->jenis]);
        return response()->json($cuti);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $cuti = Cuti::findOrFail($id);
        $cuti->delete();
        return response()->json(['message' => 'Cuti deleted']);
    }
}
