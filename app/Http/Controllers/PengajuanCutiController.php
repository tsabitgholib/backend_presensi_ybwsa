<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PengajuanCuti;
use Illuminate\Support\Facades\Auth;

class PengajuanCutiController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'cuti_id' => 'required|exists:cuti,id',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'alasan' => 'required|string',
            'dokumen' => 'nullable|file|mimes:jpg,png,pdf|max:2048',
        ]);

        $data = $request->only(['cuti_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan']);
        $pegawai = $request->get('pegawai');
        $data['pegawai_id'] = $pegawai->id;

        if ($request->hasFile('dokumen')) {
            $data['dokumen'] = $request->file('dokumen')->store('pengajuan_cuti', 'public');
        }

        $pengajuan = PengajuanCuti::create($data);

        return response()->json(['message' => 'Pengajuan cuti berhasil', 'data' => $pengajuan], 201);
    }

    public function index(Request $request)
    {
        $admin = $request->get('admin');
        $unitId = $admin->unit_id;

        $pengajuan = PengajuanCuti::query()
            ->join('ms_pegawai', 'pengajuan_cuti.pegawai_id', '=', 'ms_pegawai.id')
            ->join('ms_orang', 'ms_pegawai.id_orang', '=', 'ms_orang.id')
            ->join('ms_unit', 'ms_unit.id', '=', 'ms_pegawai.id_unit')
            ->where('ms_unit.id', $unitId)
            ->orderBy('pengajuan_cuti.id', 'desc')
            ->select('pengajuan_cuti.*', 'ms_orang.nama')->paginate(10);

        return response()->json($pengajuan);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:diterima,ditolak',
            'keterangan_admin' => 'nullable|string'
        ]);

        $pengajuan = PengajuanCuti::findOrFail($id);

        $admin = $request->get('admin');
        $unitId = $admin->unit_id;
        // $isPegawaiInUnit = \App\Models\MsPegawai::where('id', $pengajuan->pegawai_id)
        //     ->whereHas('unitDetail', function($q) use ($unitId) {
        //         $q->where('unit_id', $unitId);
        //     })->exists();

        // if (!$isPegawaiInUnit) {
        //     return response()->json(['message' => 'Tidak berhak memproses pengajuan ini'], 403);
        // }

        $pengajuan->status = $request->status;
        $pengajuan->admin_unit_id = $admin->id;
        $pengajuan->keterangan_admin = $request->keterangan_admin;
        $pengajuan->save();

        // Integrasikan ke presensi jika diterima
        if ($request->status === 'diterima') {
            $presensiController = new \App\Http\Controllers\PresensiController();
            $keterangan = "{$pengajuan->alasan}";
            $presensiController->integratePengajuanToPresensi(
                $pengajuan->pegawai_id,
                'cuti',
                $pengajuan->tanggal_mulai,
                $pengajuan->tanggal_selesai,
                $keterangan
            );
        } else {
            // Hapus dari presensi jika ditolak
            $presensiController = new \App\Http\Controllers\PresensiController();
            $presensiController->removePengajuanFromPresensi(
                $pengajuan->pegawai_id,
                'cuti',
                $pengajuan->tanggal_mulai,
                $pengajuan->tanggal_selesai
            );
        }

        return response()->json(['message' => 'Status pengajuan diperbarui', 'data' => $pengajuan]);
    }

    public function history(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $history = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($history);
    }
}
