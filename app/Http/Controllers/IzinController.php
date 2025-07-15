<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Izin;

class IzinController extends Controller
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
    public function index(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        return response()->json(Izin::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $izin = Izin::create(['jenis' => $request->jenis]);
        return response()->json($izin);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $izin = Izin::findOrFail($id);
        return response()->json($izin);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $izin = Izin::findOrFail($id);
        $izin->update(['jenis' => $request->jenis]);
        return response()->json($izin);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $izin = Izin::findOrFail($id);
        $izin->delete();
        return response()->json(['message' => 'Izin deleted']);
    }
}