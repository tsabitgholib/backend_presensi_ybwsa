<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MsPegawai;
use Illuminate\Support\Facades\Hash;

class PegawaiController extends Controller
{
    public function index()
    {
        return response()->json(MsPegawai::with('unitDetail')->paginate(20));
    }

    public function store(Request $request)
    {
        $request->validate([
            'no_ktp' => 'required|unique:ms_pegawai,no_ktp',
            'nama_depan' => 'required',
            'email' => 'required|email|unique:ms_pegawai,email',
            'password' => 'required|min:6',
            'unit_detail_id' => 'required|exists:unit_detail,id',
        ]);
        try {
            $pegawai = MsPegawai::create([
                'no_ktp' => $request->no_ktp,
                'nama_depan' => $request->nama_depan,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'unit_detail_id' => $request->unit_detail_id,
            ] + $request->except(['no_ktp', 'nama_depan', 'email', 'password', 'unit_detail_id']));
            return response()->json($pegawai);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function update(Request $request, $id)
    {
        $pegawai = MsPegawai::find($id);
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }
        $request->validate([
            'no_ktp' => 'sometimes|required|unique:ms_pegawai,no_ktp,' . $id,
            'nama_depan' => 'sometimes|required',
            'email' => 'sometimes|required|email|unique:ms_pegawai,email,' . $id,
            'password' => 'nullable|min:6',
            'unit_detail_id' => 'sometimes|required|exists:unit_detail,id',
        ]);
        try {
            $data = $request->only(['no_ktp', 'nama_depan', 'email', 'unit_detail_id']);
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $pegawai->update($data + $request->except(['no_ktp', 'nama_depan', 'email', 'password', 'unit_detail_id']));
            return response()->json($pegawai);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $pegawai = MsPegawai::find($id);
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }
        try {
            $pegawai->delete();
            return response()->json(['message' => 'Pegawai deleted']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function getByUnitIdPresensi(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $pegawais = MsPegawai::where('unit_id_presensi', $admin->unit_id)->with('unitDetail')->get();
        return response()->json($pegawais);
    }

    /**
     * Tambahkan pegawai ke unit presensi tertentu.
     * Request: { unit_id: int, pegawai_ids: array of int }
     */
    public function assignToUnitPresensi(Request $request)
    {
        $request->validate([
            'unit_id' => 'required|exists:unit,id',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
        ]);
        $count = \App\Models\MsPegawai::whereIn('id', $request->pegawai_ids)
            ->update(['unit_id_presensi' => $request->unit_id]);
        return response()->json([
            'message' => 'Berhasil menambahkan pegawai ke unit presensi',
            'jumlah_pegawai_diupdate' => $count
        ]);
    }
}
