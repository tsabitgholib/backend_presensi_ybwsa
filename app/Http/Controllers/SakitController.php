<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sakit;

class SakitController extends Controller
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
        return response()->json(Sakit::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $sakit = Sakit::create(['jenis' => $request->jenis]);
        return response()->json($sakit);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $sakit = Sakit::findOrFail($id);
        return response()->json($sakit);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $request->validate(['jenis' => 'required|string']);
        $sakit = Sakit::findOrFail($id);
        $sakit->update(['jenis' => $request->jenis]);
        return response()->json($sakit);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeSuperAdmin($request);
        $sakit = Sakit::findOrFail($id);
        $sakit->delete();
        return response()->json(['message' => 'Sakit deleted']);
    }
}
