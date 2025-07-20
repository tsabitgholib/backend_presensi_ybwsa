<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PengajuanIzin;
use Illuminate\Support\Facades\Auth;

class PengajuanIzinController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'izin_id' => 'required|exists:izin,id',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'alasan' => 'required|string',
            'dokumen' => 'nullable|file|mimes:jpg,png,pdf|max:2048',
        ]);

        $data = $request->only(['izin_id', 'tanggal_mulai', 'tanggal_selesai', 'alasan']);
        $pegawai = $request->get('pegawai');
        $data['pegawai_id'] = $pegawai->id;

        if ($request->hasFile('dokumen')) {
            $data['dokumen'] = $request->file('dokumen')->store('pengajuan_izin', 'public');
        }

        $pengajuan = PengajuanIzin::create($data);

        return response()->json(['message' => 'Pengajuan izin berhasil', 'data' => $pengajuan], 201);
    }

    public function index(Request $request)
    {
        $admin = $request->get('admin');
        $unitId = $admin->unit_id;

        $pengajuan = PengajuanIzin::query()
            ->join('ms_pegawai', 'pengajuan_izin.pegawai_id', '=', 'ms_pegawai.id')
            ->join('unit', 'unit.id', '=', 'ms_pegawai.unit_id_presensi')
            ->where('ms_pegawai.unit_id_presensi', $unitId)
            ->select('pengajuan_izin.*')->paginate(10);

        return response()->json($pengajuan);
    }

    public function approve(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:diterima,ditolak',
            'keterangan_admin' => 'nullable|string'
        ]);

        $pengajuan = PengajuanIzin::findOrFail($id);

        $admin = $request->get('admin');
        $unitId = $admin->unit_id;
        $isPegawaiInUnit = \App\Models\MsPegawai::where('id', $pengajuan->pegawai_id)
            ->whereHas('unit', function($q) use ($unitId) {
                $q->where('unit_id', $unitId);
            })->exists();

        if (!$isPegawaiInUnit) {
            return response()->json(['message' => 'Tidak berhak memproses pengajuan ini'], 403);
        }

        $pengajuan->status = $request->status;
        $pengajuan->admin_unit_id = $admin->id;
        $pengajuan->keterangan_admin = $request->keterangan_admin;
        $pengajuan->save();

        return response()->json(['message' => 'Status pengajuan diperbarui', 'data' => $pengajuan]);
    }

    public function history(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $history = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($history);
    }
} 