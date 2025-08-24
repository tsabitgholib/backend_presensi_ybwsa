<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MsPegawai;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Helpers\AdminUnitHelper;

class PegawaiController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('pegawai')
            ->leftJoin('unit_detail', 'pegawai.unit_detail_id_presensi', '=', 'unit_detail.id')
            ->leftJoin('unit', 'unit_detail.unit_id', '=', 'unit.id')
            ->leftJoin('shift_detail', 'pegawai.shift_detail_id', '=', 'shift_detail.id')
            ->leftJoin('shift', 'shift_detail.shift_id', '=', 'shift.id')
            ->select('pegawai.*', 'unit_detail.name as unit_detail_name', 'unit.name as unit_name', 'shift.name as shift_name', 'unit.id as unit_id_presensi');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('pegawai.nama', 'like', "%$search%")
                    ->orWhere('pegawai.no_ktp', 'like', "%$search%")
                    ->orWhere('unit.name', 'like', "%$search%")
                    ->orWhere('unit_detail.name', 'like', "%$search%")
                    ->orWhere('shift.name', 'like', "%$search%");
            });
        } else {
            if ($request->filled('nama')) {
                $query->where('pegawai.nama', 'like', '%' . $request->nama . '%');
            }
            if ($request->filled('nik')) {
                $query->where('pegawai.no_ktp', 'like', '%' . $request->nik . '%');
            }
            if ($request->filled('unit')) {
                $query->where('unit.name', 'like', '%' . $request->unit . '%');
            }
            if ($request->filled('unit_detail')) {
                $query->where('unit_detail.name', 'like', '%' . $request->unit_detail . '%');
            }
            if ($request->filled('shift')) {
                $query->where('shift.name', 'like', '%' . $request->shift . '%');
            }
        }

        $pegawaiPaginate = $query->paginate(20);
        return response()->json($pegawaiPaginate);
    }

    public function store(Request $request)
    {
        $request->validate([
            'no_ktp' => 'required|unique:pegawai,no_ktp',
            'nama' => 'required',
            'email' => 'required|email|unique:pegawai,email',
            'password' => 'required|min:6',
            'unit_detail_id_presensi' => 'required|exists:unit_detail,id',
        ]);
        try {
            $pegawai = MsPegawai::create([
                'no_ktp' => $request->no_ktp,
                'nama' => $request->nama,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'unit_detail_id_presensi' => $request->unit_detail_id_presensi,
            ] + $request->except(['no_ktp', 'nama', 'email', 'password', 'unit_detail_id_presensi']));
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
            'no_ktp' => 'sometimes|required|unique:pegawai,no_ktp,' . $id,
            'nama' => 'sometimes|required',
            'email' => 'sometimes|required|email|unique:pegawai,email,' . $id,
            'password' => 'nullable|min:6',
            'unit_detail_id_presensi' => 'sometimes|required|exists:unit_detail,id',
        ]);
        try {
            $data = $request->only(['no_ktp', 'nama', 'email', 'unit_detail_id_presensi']);
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $pegawai->update($data + $request->except(['no_ktp', 'nama', 'email', 'password', 'unit_detail_id_presensi']));
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
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $query = DB::table('pegawai')
            ->leftJoin('unit_detail', 'pegawai.unit_detail_id_presensi', '=', 'unit_detail.id')
            ->leftJoin('unit', 'unit_detail.unit_id', '=', 'unit.id')
            ->leftJoin('shift_detail', 'pegawai.shift_detail_id', '=', 'shift_detail.id')
            ->leftJoin('shift', 'shift_detail.shift_id', '=', 'shift.id')
            ->select('pegawai.*', 'unit_detail.name as unit_detail_name', 'unit.name as unit_name', 'shift.name as shift_name')
            ->where('unit_detail.unit_id', $unitId);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('pegawai.nama', 'like', "%$search%")
                    ->orWhere('pegawai.no_ktp', 'like', "%$search%")
                    ->orWhere('unit.name', 'like', "%$search%")
                    ->orWhere('unit_detail.name', 'like', "%$search%")
                    ->orWhere('shift.name', 'like', "%$search%");
            });
        } else {
            if ($request->filled('nama')) {
                $query->where('pegawai.nama', 'like', '%' . $request->nama . '%');
            }
            if ($request->filled('nik')) {
                $query->where('pegawai.no_ktp', 'like', '%' . $request->nik . '%');
            }
            if ($request->filled('unit')) {
                $query->where('unit.name', 'like', '%' . $request->unit . '%');
            }
            if ($request->filled('unit_detail')) {
                $query->where('unit_detail.name', 'like', '%' . $request->unit_detail . '%');
            }
            if ($request->filled('shift')) {
                $query->where('shift.name', 'like', '%' . $request->shift . '%');
            }
        }

        $pegawais = $query->paginate(20);
        return response()->json($pegawais);
    }

    /**
     * Tambahkan pegawai ke unit detail presensi tertentu.
     * Request: { unit_detail_id_presensi: int, pegawai_ids: array of int }
     */
    // public function assignToUnitPresensi(Request $request)
    // {
    //     $request->validate([
    //         'unit_detail_id_presensi' => 'required|exists:unit_detail,id',
    //         'pegawai_ids' => 'required|array',
    //         'pegawai_ids.*' => 'exists:pegawai,id',
    //     ]);
    //     $count = \App\Models\MsPegawai::whereIn('id', $request->pegawai_ids)
    //         ->update(['unit_detail_id_presensi' => $request->unit_detail_id_presensi]);
    //     return response()->json([
    //         'message' => 'Berhasil menambahkan pegawai ke unit detail presensi',
    //         'jumlah_pegawai_diupdate' => $count
    //     ]);
    // }

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
            'nama' => $pegawai->nama,
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
