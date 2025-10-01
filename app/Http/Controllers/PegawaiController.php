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
        $query = DB::table('ms_orang')
            //->leftJoin('unit_detail', 'pegawai.unit_detail_id_presensi', '=', 'unit_detail.id')
            ->leftJoin('ms_pegawai', 'ms_orang.id', '=', 'ms_pegawai.id_orang')
            ->leftJoin('ms_unit', 'ms_unit.id', '=', 'ms_pegawai.id_unit')
            //->leftJoin('ms_unit', 'ms_orang.presensi_ms_unit_detail_id', '=', 'presensi_ms_unit_detail.ms_unit_id')
            ->leftJoin('presensi_ms_unit_detail', 'presensi_ms_unit_detail.ms_unit_id', '=', 'ms_unit.id')
            ->leftJoin('shift_detail', 'ms_pegawai.presensi_shift_detail_id', '=', 'shift_detail.id')
            ->leftJoin('shift', 'shift_detail.shift_id', '=', 'shift.id')
            ->select(
                'ms_orang.id',
                'ms_orang.no_ktp',
                DB::raw("TRIM(
                    CONCAT_WS(' ', ms_orang.gelar_depan, ms_orang.nama,
                        CASE WHEN ms_orang.gelar_belakang <> '' THEN CONCAT(', ', ms_orang.gelar_belakang) END
                    )
                ) AS nama"),
                 'ms_orang.tmpt_lahir',
                 'ms_orang.tgl_lahir',
                 'ms_orang.jenis_kelamin',
                 'ms_orang.alamat_ktp',
                 'ms_orang.no_hp',
                 'ms_unit.nama as nama_unit',
                 'shift.name as nama_shift',
                 'presensi_ms_unit_detail.lokasi as lokasi_presensi'
            );


        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ms_orang.nama', 'like', "%$search%")
                    ->orWhere('ms_orang.no_ktp', 'like', "%$search%")
                    ->orWhere('ms_unit.nama', 'like', "%$search%")
                    ->orWhere('shift.name', 'like', "%$search%");
                    ;
            });
        } else {
            if ($request->filled('nama')) {
                $query->where('ms_orang.nama', 'like', '%' . $request->nama . '%');
            }
            if ($request->filled('nik')) {
                $query->where('ms_orang.no_ktp', 'like', '%' . $request->nik . '%');
            }
            if ($request->filled('ms_unit')) {
                $query->where('ms_unit.name', 'like', '%' . $request->unit . '%');
            }
            if ($request->filled('shift')) {
                $query->where('shift.name', 'like', '%' . $request->shift . '%');
            }
        }

        $pegawaiPaginate = $query->paginate(20);
        return response()->json($pegawaiPaginate);
    }

    // public function store(Request $request)
    // {
    //     $request->validate([
    //         'no_ktp' => 'required|unique:pegawai,no_ktp',
    //         'nama' => 'required',
    //         'email' => 'required|email|unique:pegawai,email',
    //         'password' => 'required|min:6',
    //         'unit_detail_id_presensi' => 'required|exists:unit_detail,id',
    //     ]);
    //     try {
    //         $pegawai = MsPegawai::create([
    //             'no_ktp' => $request->no_ktp,
    //             'nama' => $request->nama,
    //             'email' => $request->email,
    //             'password' => Hash::make($request->password),
    //             'unit_detail_id_presensi' => $request->unit_detail_id_presensi,
    //         ] + $request->except(['no_ktp', 'nama', 'email', 'password', 'unit_detail_id_presensi']));
    //         return response()->json($pegawai);
    //     } catch (\Exception $e) {
    //         return response()->json(['message' => $e->getMessage()], 400);
    //     }
    // }

    // public function update(Request $request, $id)
    // {
    //     $pegawai = MsPegawai::find($id);
    //     if (!$pegawai) {
    //         return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
    //     }
    //     $request->validate([
    //         'no_ktp' => 'sometimes|required|unique:pegawai,no_ktp,' . $id,
    //         'nama' => 'sometimes|required',
    //         'email' => 'sometimes|required|email|unique:pegawai,email,' . $id,
    //         'password' => 'nullable|min:6',
    //         'unit_detail_id_presensi' => 'sometimes|required|exists:unit_detail,id',
    //     ]);
    //     try {
    //         $data = $request->only(['no_ktp', 'nama', 'email', 'unit_detail_id_presensi']);
    //         if ($request->filled('password')) {
    //             $data['password'] = Hash::make($request->password);
    //         }
    //         $pegawai->update($data + $request->except(['no_ktp', 'nama', 'email', 'password', 'unit_detail_id_presensi']));
    //         return response()->json($pegawai);
    //     } catch (\Exception $e) {
    //         return response()->json(['message' => $e->getMessage()], 400);
    //     }
    // }

    // public function destroy($id)
    // {
    //     $pegawai = MsPegawai::find($id);
    //     if (!$pegawai) {
    //         return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
    //     }
    //     try {
    //         $pegawai->delete();
    //         return response()->json(['message' => 'Pegawai deleted']);
    //     } catch (\Exception $e) {
    //         return response()->json(['message' => $e->getMessage()], 400);
    //     }
    // }

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

        $unitIds = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id, id_parent
                FROM ms_unit
                WHERE id = ?
                UNION ALL
                SELECT u.id, u.id_parent
                FROM ms_unit u
                INNER JOIN unit_tree ut ON u.id_parent = ut.id
            )
            SELECT id FROM unit_tree
        ", [$unitId]);

        $unitIds = collect($unitIds)->pluck('id')->toArray();

        $query = DB::table('ms_orang')
            ->leftJoin('ms_pegawai', 'ms_orang.id', '=', 'ms_pegawai.id_orang')
            ->leftJoin('ms_unit', 'ms_unit.id', '=', 'ms_pegawai.id_unit')
            ->leftJoin('presensi_ms_unit_detail', 'presensi_ms_unit_detail.ms_unit_id', '=', 'ms_unit.id')
            ->leftJoin('shift_detail', 'ms_pegawai.presensi_shift_detail_id', '=', 'shift_detail.id')
            ->leftJoin('shift', 'shift_detail.shift_id', '=', 'shift.id')
            ->select(
                'ms_orang.id',
                'ms_orang.no_ktp',
                DB::raw("TRIM(
                    CONCAT_WS(' ', ms_orang.gelar_depan, ms_orang.nama,
                        CASE WHEN ms_orang.gelar_belakang <> '' THEN CONCAT(', ', ms_orang.gelar_belakang) END
                    )
                ) AS nama"),
                'ms_orang.tmpt_lahir',
                'ms_orang.tgl_lahir',
                'ms_orang.jenis_kelamin',
                'ms_orang.alamat_ktp',
                'ms_orang.no_hp',
                'ms_unit.nama as nama_unit',
                'shift.name as nama_shift',
                'presensi_ms_unit_detail.lokasi as lokasi_presensi'
            )
            ->whereIn('ms_pegawai.id_unit', $unitIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ms_orang.nama', 'like', "%$search%")
                    ->orWhere('ms_orang.no_ktp', 'like', "%$search%")
                    ->orWhere('ms_unit.nama', 'like', "%$search%")
                    ->orWhere('shift.name', 'like', "%$search%");
            });
        } else {
            if ($request->filled('nama')) {
                $query->where('ms_orang.nama', 'like', '%' . $request->nama . '%');
            }
            if ($request->filled('nik')) {
                $query->where('ms_orang.no_ktp', 'like', '%' . $request->nik . '%');
            }
            if ($request->filled('ms_unit')) {
                $query->where('ms_unit.nama', 'like', '%' . $request->unit . '%');
            }
            if ($request->filled('shift')) {
                $query->where('shift.name', 'like', '%' . $request->shift . '%');
            }
        }

        $pegawais = $query->paginate(20);
        return response()->json($pegawais);
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
        $pegawai->load([
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit'
        ]);

        if (!$pegawai->unitDetailPresensi) {
            return response()->json(['message' => 'Lokasi presensi tidak ditemukan untuk pegawai ini'], 404);
        }
        
        $namaLengkap = trim(implode(' ', [
            $pegawai->gelar_depan,
            $pegawai->nama,
            $pegawai->gelar_belakang,
        ]));

        $unitDetail = $pegawai->pegawai->unitDetailPresensi;

        $lokasi_presensi = [];

        if ($unitDetail) {
            // lokasi utama
            if (!empty($unitDetail->lokasi) && is_array($unitDetail->lokasi) && count($unitDetail->lokasi) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                    'lokasi' => $unitDetail->lokasi,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }

            // lokasi 2
            if (!empty($unitDetail->lokasi2) && is_array($unitDetail->lokasi2) && count($unitDetail->lokasi2) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama  . ' - Area 2',
                    'lokasi' => $unitDetail->lokasi2,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }

            // lokasi 3
            if (!empty($unitDetail->lokasi3) && is_array($unitDetail->lokasi3) && count($unitDetail->lokasi3) > 0) {
                $lokasi_presensi[] = [
                    'unit_detail_id' => $unitDetail->id,
                    'nama_lokasi' => $pegawai->pegawai->unitDetailPresensi->unit->nama .' - Area 3',
                    'lokasi' => $unitDetail->lokasi3,
                    'unit_name' => $pegawai->pegawai->unitDetailPresensi->unit->nama ?? null,
                ];
            }
        }


        return response()->json([
            'pegawai_id' => $pegawai->id,
            'no_ktp' => $pegawai->no_ktp,
            'nama' => $namaLengkap,
            'lokasi_presensi' => $lokasi_presensi,
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

        public function getByKepalaUnit(Request $request)
    {
        $pegawai = $request->get('pegawai');

        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        $pegawai->load([
            'shiftDetail.shift',
            'unitDetailPresensi.unit',
            'pegawai'
        ]);

        if ($pegawai->pegawai->profesi !== 'Kepala Sekolah') {
            return response()->json([
                'message' => 'Anda bukan kepala unit!'
            ]);
        }

        $unitId = $pegawai->pegawai->id_unit;

        $unitIds = DB::select("
            WITH RECURSIVE unit_tree AS (
                SELECT id, id_parent
                FROM ms_unit
                WHERE id = ?
                UNION ALL
                SELECT u.id, u.id_parent
                FROM ms_unit u
                INNER JOIN unit_tree ut ON u.id_parent = ut.id
            )
            SELECT id FROM unit_tree
        ", [$unitId]);

        $unitIds = collect($unitIds)->pluck('id')->toArray();

        $query = DB::table('ms_orang')
            ->leftJoin('ms_pegawai', 'ms_orang.id', '=', 'ms_pegawai.id_orang')
            ->leftJoin('ms_unit', 'ms_unit.id', '=', 'ms_pegawai.id_unit')
            ->leftJoin('presensi_ms_unit_detail', 'presensi_ms_unit_detail.ms_unit_id', '=', 'ms_unit.id')
            ->leftJoin('shift_detail', 'ms_pegawai.presensi_shift_detail_id', '=', 'shift_detail.id')
            ->leftJoin('shift', 'shift_detail.shift_id', '=', 'shift.id')
            ->select(
                'ms_orang.id',
                'ms_orang.no_ktp',
                DB::raw("TRIM(
                    CONCAT_WS(' ', ms_orang.gelar_depan, ms_orang.nama,
                        CASE WHEN ms_orang.gelar_belakang <> '' THEN CONCAT(', ', ms_orang.gelar_belakang) END
                    )
                ) AS nama"),
                'ms_orang.tmpt_lahir',
                'ms_orang.tgl_lahir',
                'ms_orang.jenis_kelamin',
                'ms_orang.alamat_ktp',
                'ms_orang.no_hp',
                'ms_unit.nama as nama_unit',
                'shift.name as nama_shift',
                'presensi_ms_unit_detail.lokasi as lokasi_presensi'
            )
            ->whereIn('ms_pegawai.id_unit', $unitIds);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ms_orang.nama', 'like', "%$search%")
                    ->orWhere('ms_orang.no_ktp', 'like', "%$search%")
                    ->orWhere('ms_unit.nama', 'like', "%$search%")
                    ->orWhere('shift.name', 'like', "%$search%");
            });
        } else {
            if ($request->filled('nama')) {
                $query->where('ms_orang.nama', 'like', '%' . $request->nama . '%');
            }
            if ($request->filled('nik')) {
                $query->where('ms_orang.no_ktp', 'like', '%' . $request->nik . '%');
            }
            if ($request->filled('ms_unit')) {
                $query->where('ms_unit.nama', 'like', '%' . $request->unit . '%');
            }
            if ($request->filled('shift')) {
                $query->where('shift.name', 'like', '%' . $request->shift . '%');
            }
        }

        $pegawais = $query->paginate(20);
        return response()->json($pegawais);
    }

}