<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MsPegawai;
use Illuminate\Support\Facades\Hash;

class PegawaiController extends Controller
{
    public function index()
    {
        return response()->json(MsPegawai::with('unitDetailPresensi')->paginate(20));
    }

    public function store(Request $request)
    {
        $request->validate([
            'no_ktp' => 'required|unique:ms_pegawai,no_ktp',
            'nama_depan' => 'required',
            'email' => 'required|email|unique:ms_pegawai,email',
            'password' => 'required|min:6',
            'unit_detail_id_presensi' => 'required|exists:unit_detail,id',
        ]);
        try {
            $pegawai = MsPegawai::create([
                'no_ktp' => $request->no_ktp,
                'nama_depan' => $request->nama_depan,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'unit_detail_id_presensi' => $request->unit_detail_id_presensi,
            ] + $request->except(['no_ktp', 'nama_depan', 'email', 'password', 'unit_detail_id_presensi']));
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
            'unit_detail_id_presensi' => 'sometimes|required|exists:unit_detail,id',
        ]);
        try {
            $data = $request->only(['no_ktp', 'nama_depan', 'email', 'unit_detail_id_presensi']);
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $pegawai->update($data + $request->except(['no_ktp', 'nama_depan', 'email', 'password', 'unit_detail_id_presensi']));
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
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin) {
            $q->where('unit_id', $admin->unit_id);
        })->with('unitDetailPresensi')->get();
        return response()->json($pegawais);
    }

    /**
     * Tambahkan pegawai ke unit detail presensi tertentu.
     * Request: { unit_detail_id_presensi: int, pegawai_ids: array of int }
     */
    public function assignToUnitPresensi(Request $request)
    {
        $request->validate([
            'unit_detail_id_presensi' => 'required|exists:unit_detail,id',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
        ]);
        $count = \App\Models\MsPegawai::whereIn('id', $request->pegawai_ids)
            ->update(['unit_detail_id_presensi' => $request->unit_detail_id_presensi]);
        return response()->json([
            'message' => 'Berhasil menambahkan pegawai ke unit detail presensi',
            'jumlah_pegawai_diupdate' => $count
        ]);
    }

    /**
     * Get lokasi presensi yang valid untuk pegawai
     * Endpoint ini digunakan oleh Android/iOS untuk mendapatkan area lokasi yang valid untuk presensi
     */
    public function getLokasiPresensi(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        // Load relasi yang diperlukan
        $pegawai->load(['unitDetailPresensi', 'shiftDetail.shift']);

        if (!$pegawai->unitDetailPresensi) {
            return response()->json(['message' => 'Lokasi presensi tidak ditemukan untuk pegawai ini'], 404);
        }

        return response()->json([
            'pegawai_id' => $pegawai->id,
            'no_ktp' => $pegawai->no_ktp,
            'nama' => $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : ''),
            'lokasi_presensi' => [
                'unit_detail_id' => $pegawai->unitDetailPresensi->id,
                'nama_lokasi' => $pegawai->unitDetailPresensi->name,
                'polygon_lokasi' => $pegawai->unitDetailPresensi->lokasi,
                'unit_name' => $pegawai->unitDetailPresensi->unit->name ?? null
            ],
            'shift_info' => $pegawai->shiftDetail ? [
                'shift_detail_id' => $pegawai->shiftDetail->id,
                'shift_name' => $pegawai->shiftDetail->shift->name ?? null,
                'jam_kerja' => [
                    'senin' => [
                        'masuk' => $pegawai->shiftDetail->senin_masuk,
                        'pulang' => $pegawai->shiftDetail->senin_pulang
                    ],
                    'selasa' => [
                        'masuk' => $pegawai->shiftDetail->selasa_masuk,
                        'pulang' => $pegawai->shiftDetail->selasa_pulang
                    ],
                    'rabu' => [
                        'masuk' => $pegawai->shiftDetail->rabu_masuk,
                        'pulang' => $pegawai->shiftDetail->rabu_pulang
                    ],
                    'kamis' => [
                        'masuk' => $pegawai->shiftDetail->kamis_masuk,
                        'pulang' => $pegawai->shiftDetail->kamis_pulang
                    ],
                    'jumat' => [
                        'masuk' => $pegawai->shiftDetail->jumat_masuk,
                        'pulang' => $pegawai->shiftDetail->jumat_pulang
                    ],
                    'sabtu' => [
                        'masuk' => $pegawai->shiftDetail->sabtu_masuk,
                        'pulang' => $pegawai->shiftDetail->sabtu_pulang
                    ],
                    'minggu' => [
                        'masuk' => $pegawai->shiftDetail->minggu_masuk,
                        'pulang' => $pegawai->shiftDetail->minggu_pulang
                    ]
                ],
                'toleransi' => [
                    'terlambat' => $pegawai->shiftDetail->toleransi_terlambat ?? 0,
                    'pulang' => $pegawai->shiftDetail->toleransi_pulang ?? 0
                ]
            ] : null
        ]);
    }

    /**
     * Cek dan tampilkan list hari libur untuk pegawai berdasarkan unit_detail_id_presensi
     */
    public function cekHariLibur(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        // Load relasi yang diperlukan
        $pegawai->load(['unitDetailPresensi']);

        if (!$pegawai->unitDetailPresensi) {
            return response()->json(['message' => 'Lokasi presensi tidak ditemukan'], 404);
        }

        $today = \Carbon\Carbon::now('Asia/Jakarta')->toDateString();
        $bulan = $request->query('bulan', \Carbon\Carbon::now()->month);
        $tahun = $request->query('tahun', \Carbon\Carbon::now()->year);

        // Cek apakah hari ini adalah hari libur
        $isHariLibur = \App\Models\HariLibur::isHariLibur($pegawai->unitDetailPresensi->id, $today);

        // Ambil list hari libur untuk unit detail pegawai
        $listHariLibur = \App\Models\HariLibur::where('unit_detail_id', $pegawai->unitDetailPresensi->id)
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->orderBy('tanggal')
            ->get();

        $response = [
            'is_hari_libur' => $isHariLibur,
            'tanggal_hari_ini' => $today,
            'unit_detail' => [
                'id' => $pegawai->unitDetailPresensi->id,
                'name' => $pegawai->unitDetailPresensi->name
            ],
            'list_hari_libur' => $listHariLibur->map(function ($hariLibur) {
                return [
                    'id' => $hariLibur->id,
                    'tanggal' => $hariLibur->tanggal->format('Y-m-d'),
                    'keterangan' => $hariLibur->keterangan,
                    'created_at' => $hariLibur->created_at->format('Y-m-d H:i:s')
                ];
            })
        ];

        if ($isHariLibur) {
            $hariLiburHariIni = $listHariLibur->where('tanggal', $today)->first();
            $response['keterangan_hari_ini'] = $hariLiburHariIni->keterangan ?? 'Hari Libur';
        }

        return response()->json($response);
    }
}
