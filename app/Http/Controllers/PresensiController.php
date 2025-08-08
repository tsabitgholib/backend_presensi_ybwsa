<?php

namespace App\Http\Controllers;

/**
 * Status Presensi yang Standar:
 * 
 * Status Masuk:
 * - absen_masuk: Absen masuk tepat waktu
 * - terlambat: Terlambat absen masuk
 * - tidak_absen_masuk: Tidak absen masuk
 * - tidak_hadir: Tidak hadir
 * - izin: Izin
 * - sakit: Sakit
 * - cuti: Cuti
 * 
 * Status Pulang:
 * - absen_pulang: Absen pulang tepat waktu
 * - pulang_awal: Pulang sebelum waktu pulang
 * - tidak_absen_pulang: Tidak absen pulang
 * - tidak_hadir: Tidak hadir
 * - izin: Izin
 * - sakit: Sakit
 * - cuti: Cuti
 * 
 * Status Presensi (Final):
 * - hadir: Hadir (dihitung dari status masuk/pulang yang hadir)
 * - tidak_hadir: Tidak hadir
 * - sakit: Sakit
 * - izin: Izin
 * - cuti: Cuti
 */

use Illuminate\Http\Request;
use App\Models\Presensi;
use App\Models\MsPegawai;
use App\Models\Shift;
use App\Models\ShiftDetail;
use App\Models\UnitDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Helpers\AdminUnitHelper;

class PresensiController extends Controller
{
    // Fungsi point-in-polygon sederhana
    private function isPointInPolygon($point, $polygon)
    {
        $x = $point[0];
        $y = $point[1];
        $inside = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];
            $intersect = (($yi > $y) != ($yj > $y)) &&
                ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-10) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }

    public function store(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        // Load relasi yang diperlukan untuk validasi
        $pegawai->load(['shiftDetail.shift', 'unitDetailPresensi']);

        $request->validate([
            'lokasi' => 'required|array|size:2', // [lat, lng]
        ]);
        $now = \Carbon\Carbon::now('Asia/Jakarta');
        $hari = strtolower($now->locale('id')->isoFormat('dddd'));
        
        // Ambil shift_detail pegawai
        $shiftDetail = $pegawai->shiftDetail;
        if (!$shiftDetail) {
            return response()->json(['message' => 'Shift detail tidak ditemukan untuk pegawai ini'], 400);
        }
        
        // Ambil unit_detail dari pegawai
        $unitDetail = $pegawai->unitDetailPresensi;
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 400);
        }
        
        // Validasi lokasi (point-in-polygon)
        $polygon = $unitDetail->lokasi;
        if (!$this->isPointInPolygon($request->lokasi, $polygon)) {
            return response()->json(['message' => 'Lokasi di luar area'], 400);
        }
        
        // Cek apakah hari ini adalah hari libur
        $isHariLibur = \App\Models\HariLibur::isHariLibur($unitDetail->id, $now->toDateString());

        // Validasi waktu presensi
        $masukKey = $hari . '_masuk';
        $pulangKey = $hari . '_pulang';
        $jamMasuk = $shiftDetail->$masukKey;
        $jamPulang = $shiftDetail->$pulangKey;
        $tolMasuk = $shiftDetail->toleransi_terlambat ?? 0;
        $tolPulang = $shiftDetail->toleransi_pulang ?? 0;

        if (!$jamMasuk && !$jamPulang) {
            return response()->json(['message' => 'Hari ini libur, tidak ada jam masuk/pulang'], 400);
        }

        // Cek apakah sudah ada presensi hari ini (format baru)
        $presensiHariIni = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu_masuk', $now->toDateString())
            ->first();

        if ($presensiHariIni) {
            // UPDATE: Presensi pulang
            return $this->handlePresensiPulang($request, $presensiHariIni, $now, $shiftDetail, $jamPulang, $tolPulang);
        } else {
            // CREATE: Presensi masuk
            return $this->handlePresensiMasuk($request, $now, $shiftDetail, $jamMasuk, $tolMasuk, $pegawai);
        }
    }

    private function handlePresensiMasuk(Request $request, $now, $shiftDetail, $jamMasuk, $tolMasuk, $pegawai)
    {
        $statusMasuk = null;
        $keteranganMasuk = null;
        $waktuMasukUntukSimpan = $now; // Default waktu simpan adalah waktu presensi
        $jam12 = \Carbon\Carbon::createFromTime(12, 0, 0, 'Asia/Jakarta');

        // Jika presensi dilakukan setelah jam 12.00
        if ($now->greaterThanOrEqualTo($jam12)) {
            $statusMasuk = 'tidak_absen_masuk';
            $keteranganMasuk = 'Tidak absen masuk, sudah lewat jam 12:00';
            $waktuMasukUntukSimpan = $jam12; // Simpan waktu 12:00, bukan waktu presensi
        } else {
            // Validasi waktu masuk
            if ($jamMasuk) {
                try {
                    $waktuMasuk = \Carbon\Carbon::createFromFormat('H:i', $jamMasuk, 'Asia/Jakarta');
                    $batasMasuk = $waktuMasuk->copy()->addMinutes($tolMasuk);
                    
                    if ($now->lessThan($waktuMasuk)) {
                        return response()->json(['message' => 'Belum waktunya absen masuk'], 400);
                    }
                    
                    if ($now->between($waktuMasuk, $batasMasuk)) {
                        $statusMasuk = 'absen_masuk';
                    } elseif ($now->greaterThan($batasMasuk) && $now->lessThan($jam12)) {
                        $statusMasuk = 'terlambat';
                        $keteranganMasuk = 'Terlambat absen masuk';
                    }
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Format jam masuk tidak valid'], 400);
                }
            }
        }

        if (!$statusMasuk) {
            $statusMasuk = 'tidak_absen_masuk';
            $keteranganMasuk = 'Tidak absen masuk';
        }

        // Simpan presensi masuk
            $presensi = Presensi::create([
                'no_ktp' => $pegawai->no_ktp,
                'shift_id' => $shiftDetail->shift_id,
                'shift_detail_id' => $shiftDetail->id,
            'waktu_masuk' => $waktuMasukUntukSimpan, // Gunakan waktu yang sudah ditentukan
            'status_masuk' => $statusMasuk,
            'lokasi_masuk' => $request->lokasi,
            'keterangan_masuk' => $keteranganMasuk,
            'status_presensi' => in_array($statusMasuk, ['absen_masuk', 'terlambat']) ? 'hadir' : 'tidak_hadir',
            // Backward compatibility - tetap isi kolom lama
            //'waktu' => $waktuMasukUntukSimpan,
            //'status' => $statusMasuk,
            //    'lokasi' => $request->lokasi,
            //'keterangan' => $keteranganMasuk,
            ]);

            $shift_name = $shiftDetail->shift ? $shiftDetail->shift->name : null;
            return response()->json([
                'no_ktp' => $presensi->no_ktp,
                'shift_name' => $shift_name,
                'shift_detail_id' => $presensi->shift_detail_id,
            'tanggal' => $presensi->waktu_masuk->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d'),
            'waktu' => $presensi->waktu_masuk->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
            'status' => $presensi->status_masuk,
            'lokasi' => $presensi->lokasi_masuk,
            'keterangan' => $presensi->keterangan_masuk,
                'updated_at' => $presensi->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                'created_at' => $presensi->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                'id' => $presensi->id,
            ]);
        }

    private function handlePresensiPulang(Request $request, $presensi, $now, $shiftDetail, $jamPulang, $tolPulang)
    {
        $statusPulang = null;
        $keteranganPulang = null;

        // Validasi waktu pulang
        if ($jamPulang) {
            try {
                $waktuPulang = \Carbon\Carbon::createFromFormat('H:i', $jamPulang, 'Asia/Jakarta');
                $batasAwalPulang = $waktuPulang->copy()->subMinutes($tolPulang); // Jam 16:55 jika pulang 17:00, toleransi 5 menit
                
                if ($now->lessThan($batasAwalPulang)) {
                    return response()->json(['message' => 'Belum waktunya absen pulang'], 400);
                }
                
                // Logic yang benar:
                // - Jika pulang sebelum batas awal (16:55) = pulang_awal
                // - Jika pulang antara batas awal (16:55) sampai waktu pulang (17:00) = absen_pulang
                // - Jika pulang setelah waktu pulang (17:00) = absen_pulang
                if ($now->lessThan($waktuPulang)) {
                    $statusPulang = 'pulang_awal';
                    $keteranganPulang = 'Pulang sebelum waktu pulang';
                } else {
                    $statusPulang = 'absen_pulang';
                    $keteranganPulang = 'Absen pulang tepat waktu';
                }
            } catch (\Exception $e) {
                return response()->json(['message' => 'Format jam pulang tidak valid'], 400);
            }
        }

        if (!$statusPulang) {
            $statusPulang = 'tidak_absen_pulang';
            $keteranganPulang = 'Tidak absen pulang';
        }

        // Update presensi dengan data pulang
        $presensi->update([
            'waktu_pulang' => $now,
            'status_pulang' => $statusPulang,
            'lokasi_pulang' => $request->lokasi,
            'keterangan_pulang' => $keteranganPulang,
            'status_presensi' => $this->calculateFinalStatus($presensi->status_masuk, $statusPulang),
        ]);

        $shift_name = $shiftDetail->shift ? $shiftDetail->shift->name : null;
        return response()->json([
            'no_ktp' => $presensi->no_ktp,
            'shift_name' => $shift_name,
            'shift_detail_id' => $presensi->shift_detail_id,
            'tanggal' => $presensi->waktu_masuk->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d'),
            'waktu' => $presensi->waktu_pulang->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
            'status' => $presensi->status_pulang,
            'lokasi' => $presensi->lokasi_pulang,
            'keterangan' => $presensi->keterangan_pulang,
            'updated_at' => $presensi->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
            'created_at' => $presensi->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
            'id' => $presensi->id,
        ]);
    }

    private function calculateFinalStatus($statusMasuk, $statusPulang)
    {
        // Status yang dianggap hadir
        $hadirStatuses = \App\Models\Presensi::getHadirStatuses();
        
        // Status khusus yang tidak dihitung sebagai hadir
        $specialStatuses = \App\Models\Presensi::getSpecialStatuses();
        
        // Jika ada status khusus, gunakan status tersebut
        if (in_array($statusMasuk, $specialStatuses)) {
            return $statusMasuk;
        }
        if (in_array($statusPulang, $specialStatuses)) {
            return $statusPulang;
        }
        
        // Jika salah satu hadir, maka status akhir adalah hadir
        if (in_array($statusMasuk, $hadirStatuses) || in_array($statusPulang, $hadirStatuses)) {
            return \App\Models\Presensi::STATUS_PRESENSI_HADIR;
        }
        
        return \App\Models\Presensi::STATUS_PRESENSI_TIDAK_HADIR;
    }

    // Presensi hari ini (masuk & keluar)
    public function today(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $today = \Carbon\Carbon::now('Asia/Jakarta')->toDateString();
        
        // Menggunakan format baru - 1 row per hari
        $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu_masuk', $today)
            ->first();
            
        return response()->json([
            'tanggal' => $today,
            'jam_masuk' => $presensi ? $presensi->waktu_masuk?->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'jam_keluar' => $presensi ? $presensi->waktu_pulang?->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'status_masuk' => $presensi ? $presensi->status_masuk : null,
            'status_keluar' => $presensi ? $presensi->status_pulang : null,
            'status_presensi' => $presensi ? $presensi->status_presensi : null,
            'lokasi_masuk' => $presensi ? $presensi->lokasi_masuk : null,
            'lokasi_keluar' => $presensi ? $presensi->lokasi_pulang : null,
        ]);
    }

    // History presensi by tanggal
    public function history(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $tanggal = $request->query('tanggal');
        if ($tanggal) {
            // Menggunakan format baru - 1 row per hari
            $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereDate('waktu_masuk', $tanggal)
                ->first();
                
            return response()->json([
                'hari' => \Carbon\Carbon::parse($tanggal)->locale('id')->isoFormat('dddd'),
                'tanggal' => $tanggal,
                'jam_masuk' => $presensi ? $presensi->waktu_masuk?->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'jam_keluar' => $presensi ? $presensi->waktu_pulang?->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'status_masuk' => $presensi ? $presensi->status_masuk : null,
                'status_keluar' => $presensi ? $presensi->status_pulang : null,
                'status_presensi' => $presensi ? $presensi->status_presensi : null,
            ]);
        }
        
        $from = $request->query('from', \Carbon\Carbon::now('Asia/Jakarta')->startOfMonth()->toDateString());
        $to = $request->query('to', \Carbon\Carbon::now('Asia/Jakarta')->toDateString());
        
        // Menggunakan format baru - 1 row per hari
        $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween('waktu_masuk', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('waktu_masuk')
            ->get();
            
        $history = [];
        foreach ($presensi as $p) {
            $history[] = [
                'hari' => $p->waktu_masuk->locale('id')->isoFormat('dddd'),
                'tanggal' => $p->waktu_masuk->format('Y-m-d'),
                'jam_masuk' => $p->waktu_masuk?->format('H:i:s'),
                'jam_keluar' => $p->waktu_pulang?->format('H:i:s'),
                'status_masuk' => $p->status_masuk,
                'status_keluar' => $p->status_pulang,
                'status_presensi' => $p->status_presensi,
            ];
        }
        return response()->json($history);
    }

    public function rekapPresensiByAdminUnit(Request $request)
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

        $tanggal = $request->query('tanggal');
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('unit_id', $unitId);
        })->get();
        $result = [];
        foreach ($pegawais as $pegawai) {
            $query = Presensi::where('no_ktp', $pegawai->no_ktp);
            if ($tanggal) {
                $query->whereDate('waktu_masuk', $tanggal);
            }
            $presensis = $query->get();
            $total_hadir = $presensis->where('status_presensi', 'hadir')->count();
            $total_tidak_masuk = $presensis->where('status_presensi', 'tidak_hadir')->count();

            // Hitung total izin, cuti, sakit dari tabel pengajuan masing-masing dengan status 'diterima'
            $total_izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->when($tanggal, function ($q) use ($tanggal) {
                    $q->whereDate('tanggal_mulai', '<=', $tanggal)
                        ->whereDate('tanggal_selesai', '>=', $tanggal);
                })->count();
            $total_cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->when($tanggal, function ($q) use ($tanggal) {
                    $q->whereDate('tanggal_mulai', '<=', $tanggal)
                        ->whereDate('tanggal_selesai', '>=', $tanggal);
                })->count();
            $total_sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->when($tanggal, function ($q) use ($tanggal) {
                    $q->whereDate('tanggal_mulai', '<=', $tanggal)
                        ->whereDate('tanggal_selesai', '>=', $tanggal);
                })->count();

            $result[] = [
                'id' => $pegawai->id,
                'no_ktp' => $pegawai->no_ktp,
                'nama' => $pegawai->nama,
                'total_hadir' => $total_hadir,
                'total_tidak_masuk' => $total_tidak_masuk,
                'total_izin' => $total_izin,
                'total_cuti' => $total_cuti,
                'total_sakit' => $total_sakit,
            ];
        }
        return response()->json($result);
    }

    public function historyByAdminUnit(Request $request)
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

        $tanggal = $request->query('tanggal');
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('unit_id', $unitId);
        })->get(['id', 'no_ktp', 'nama']);
        $no_ktps = $pegawais->pluck('no_ktp');
        $pegawaiMap = $pegawais->keyBy('no_ktp');
        
        // Menggunakan format baru - 1 row per hari
        $query = Presensi::whereIn('no_ktp', $no_ktps);
        if ($tanggal) {
            $query->whereDate('waktu_masuk', $tanggal);
        }
        $presensis = $query->orderBy('waktu_masuk', 'desc')->get();
        $result = $presensis->map(function ($p) use ($pegawaiMap) {
            $pegawai = $pegawaiMap[$p->no_ktp] ?? null;
            return [
                'id' => $p->id,
                'no_ktp' => $p->no_ktp,
                'nama' => $pegawai ? $pegawai->nama : null,
                'status_masuk' => $p->status_masuk,
                'status_pulang' => $p->status_pulang,
                'status_presensi' => $p->status_presensi,
                'waktu_masuk' => $p->waktu_masuk,
                'waktu_pulang' => $p->waktu_pulang,
                'keterangan_masuk' => $p->keterangan_masuk,
                'keterangan_pulang' => $p->keterangan_pulang,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ];
        });
        return response()->json($result);
    }

    /**
     * Detail history presensi pegawai di unit detail tertentu (admin unit)
     * Bisa filter by pegawai, dan update presensi oleh admin unit
     * Menampilkan data presensi berpasangan (masuk dan pulang) dalam satu hari
     */
    public function detailHistoryByAdminUnit(Request $request)
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

        $unit_detail_id = $request->query('unit_detail_id');
        $pegawai_id = $request->query('pegawai_id');
        $from = $request->query('from');
        $to = $request->query('to');

        // Ambil semua pegawai di unit admin (jika unit_detail_id tidak diisi, ambil semua di unit admin)
        $pegawaiQuery = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId, $unit_detail_id) {
            $q->where('unit_id', $unitId);
            if ($unit_detail_id) {
                $q->where('id', $unit_detail_id);
            }
        });
        if ($pegawai_id) {
            $pegawaiQuery->where('id', $pegawai_id);
        }
        $pegawais = $pegawaiQuery->get();

        $result = [];
        foreach ($pegawais as $pegawai) {
            $presensiQuery = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp);
            if ($from) {
                $presensiQuery->whereDate('waktu_masuk', '>=', $from);
            }
            if ($to) {
                $presensiQuery->whereDate('waktu_masuk', '<=', $to);
            }
            // Menggunakan format baru - 1 row per hari
            $presensi = $presensiQuery->orderBy('waktu_masuk', 'asc')->get();

            $presensiBerpasangan = [];
            foreach ($presensi as $p) {
                $presensiBerpasangan[] = [
                    'tanggal' => $p->waktu_masuk->format('Y-m-d'),
                    'hari' => $p->waktu_masuk->locale('id')->isoFormat('dddd'),
                    'status_presensi' => $p->status_presensi,
                    'masuk' => [
                        'id' => $p->id,
                        'waktu' => $p->waktu_masuk->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
                        'status' => $p->status_masuk,
                        'lokasi' => $p->lokasi_masuk,
                        'keterangan' => $p->keterangan_masuk,
                        'created_at' => $p->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                        'updated_at' => $p->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                    ],
                    'pulang' => $p->waktu_pulang ? [
                        'id' => $p->id,
                        'waktu' => $p->waktu_pulang->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
                        'status' => $p->status_pulang,
                        'lokasi' => $p->lokasi_pulang,
                        'keterangan' => $p->keterangan_pulang,
                        'created_at' => $p->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                        'updated_at' => $p->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                    ] : null,
                ];
            }

            // Ambil unit detail name
            $unitDetailName = null;
            if ($pegawai->unit_detail_id_presensi) {
                $unitDetail = \App\Models\UnitDetail::find($pegawai->unit_detail_id_presensi);
                $unitDetailName = $unitDetail ? $unitDetail->name : null;
            }

            $result[] = [
                'pegawai' => [
                    'id' => $pegawai->id,
                    'no_ktp' => $pegawai->no_ktp,
                    'nama' => $pegawai->nama,
                    'unit_detail_name' => $unitDetailName,
                ],
                'presensi' => $presensiBerpasangan,
            ];
        }
        return response()->json($result);
    }

    /**
     * Update presensi pegawai secara bulk oleh admin unit
     */
    public function updatePresensiByAdminUnitBulk(Request $request, $pegawai_id, $tanggal)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $updates = $request->input('updates');
        if (!$pegawai_id || !$tanggal || !is_array($updates)) {
            return response()->json(['message' => 'pegawai_id, tanggal, dan updates wajib diisi'], 422);
        }
        
        // Validasi format tanggal
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            return response()->json(['message' => 'Format tanggal tidak valid. Gunakan format YYYY-MM-DD'], 422);
        }
        $pegawai = \App\Models\MsPegawai::find($pegawai_id);
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // Validasi pegawai milik unit admin
        if (!$pegawai->unitDetailPresensi || $pegawai->unitDetailPresensi->unit_id != $unitId) {
            return response()->json(['message' => 'Tidak memiliki akses edit presensi pegawai ini'], 403);
        }

        // Menggunakan format baru - 1 row per hari
        $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu_masuk', $tanggal)
            ->first();
        if (!$presensi) {
            return response()->json(['message' => 'Tidak ada presensi pada tanggal tersebut'], 404);
        }
        $updated = [];
        foreach ($updates as $update) {
            $statusMasuk = $update['status_masuk'] ?? null;
            $statusPulang = $update['status_pulang'] ?? null;
            $waktuMasuk = $update['waktu_masuk'] ?? null;
            $waktuPulang = $update['waktu_pulang'] ?? null;
            
            // Validasi status masuk
            if ($statusMasuk && !\App\Models\Presensi::isValidStatusMasuk($statusMasuk)) {
                return response()->json(['message' => 'Status masuk tidak valid'], 422);
            }
            
            // Validasi status pulang
            if ($statusPulang && !\App\Models\Presensi::isValidStatusPulang($statusPulang)) {
                return response()->json(['message' => 'Status pulang tidak valid'], 422);
            }
            
            // Konversi input waktu jika dalam format jam (HH:mm)
            if ($waktuMasuk && !str_contains($waktuMasuk, '-') && !str_contains($waktuMasuk, 'T')) {
                // Validasi format jam (HH:mm)
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $waktuMasuk)) {
                    return response()->json(['message' => 'Format waktu masuk tidak valid. Gunakan format HH:mm'], 422);
                }
                $waktuMasuk = $tanggal . ' ' . $waktuMasuk . ':00';
            }
            
            if ($waktuPulang && !str_contains($waktuPulang, '-') && !str_contains($waktuPulang, 'T')) {
                // Validasi format jam (HH:mm)
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $waktuPulang)) {
                    return response()->json(['message' => 'Format waktu pulang tidak valid. Gunakan format HH:mm'], 422);
                }
                $waktuPulang = $tanggal . ' ' . $waktuPulang . ':00';
            }
            
            // Validasi logika waktu (waktu pulang harus setelah waktu masuk)
            if ($waktuMasuk && $waktuPulang) {
                $waktuMasukObj = \Carbon\Carbon::parse($waktuMasuk);
                $waktuPulangObj = \Carbon\Carbon::parse($waktuPulang);
                
                if ($waktuPulangObj->lte($waktuMasukObj)) {
                    return response()->json(['message' => 'Waktu pulang harus setelah waktu masuk'], 422);
                }
            }
            
            // Update presensi dengan data baru
            $updateData = array_filter([
                'status_masuk' => $statusMasuk,
                'status_pulang' => $statusPulang,
                'waktu_masuk' => $waktuMasuk,
                'waktu_pulang' => $waktuPulang,
                'lokasi_masuk' => $update['lokasi_masuk'] ?? null,
                'lokasi_pulang' => $update['lokasi_pulang'] ?? null,
                'keterangan_masuk' => $update['keterangan_masuk'] ?? null,
                'keterangan_pulang' => $update['keterangan_pulang'] ?? null,
                'status_presensi' => $update['status_presensi'] ?? null,
            ], fn($v) => $v !== null);
            
            // Recalculate status_presensi jika ada perubahan status
            if ($statusMasuk || $statusPulang) {
                $updateData['status_presensi'] = $this->calculateFinalStatus(
                    $statusMasuk ?? $presensi->status_masuk,
                    $statusPulang ?? $presensi->status_pulang
                );
            }
            
            $presensi->update($updateData);
            $updated[] = $presensi;
        }
        return response()->json([
            'message' => 'Presensi berhasil diupdate',
            'updated' => $updated
        ]);
    }

    public function rekapBulananUnitByAdmin(Request $request)
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

        $tahun = $request->query('tahun', now('Asia/Jakarta')->year);
        $bulanSekarang = now('Asia/Jakarta')->month;
        $result = [];
        $namaBulan = [
            1 => 'januari',
            2 => 'februari',
            3 => 'maret',
            4 => 'april',
            5 => 'mei',
            6 => 'juni',
            7 => 'juli',
            8 => 'agustus',
            9 => 'september',
            10 => 'oktober',
            11 => 'november',
            12 => 'desember'
        ];
        for ($bulan = 1; $bulan <= $bulanSekarang; $bulan++) {
            // Ambil semua pegawai di unit admin
            $pegawais = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
                $q->where('unit_id', $unitId);
            })->get();
            $rekapBulan = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'cuti' => 0,
                'tidak_hadir' => 0,
                'belum_presensi' => 0
            ];
            $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = $start->copy()->endOfMonth();
            $jumlahHari = $end->day;
            foreach ($pegawais as $pegawai) {
                // Ambil presensi pegawai di bulan tsb (format baru)
                $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                    ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                    ->orderBy('waktu_masuk')
                    ->get();
                $izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
                    ->where('status', 'diterima')
                    ->where(function ($q) use ($start, $end) {
                        $q->whereBetween('tanggal_mulai', [$start, $end])
                            ->orWhereBetween('tanggal_selesai', [$start, $end]);
                    })->get();
                $cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
                    ->where('status', 'diterima')
                    ->where(function ($q) use ($start, $end) {
                        $q->whereBetween('tanggal_mulai', [$start, $end])
                            ->orWhereBetween('tanggal_selesai', [$start, $end]);
                    })->get();
                $sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
                    ->where('status', 'diterima')
                    ->where(function ($q) use ($start, $end) {
                        $q->whereBetween('tanggal_mulai', [$start, $end])
                            ->orWhereBetween('tanggal_selesai', [$start, $end]);
                    })->get();
                for ($hari = 1; $hari <= $jumlahHari; $hari++) {
                    $tanggal = $start->copy()->day($hari)->format('Y-m-d');
                    $status = null;
                    // Menggunakan format baru - 1 row per hari
                    $presensiHari = $presensi->where(fn($p) => $p->waktu_masuk->format('Y-m-d') === $tanggal);
                    if ($presensiHari->count()) {
                        if ($presensiHari->where('status_presensi', 'hadir')->count()) {
                            $status = 'hadir';
                        } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                            $status = 'tidak_hadir';
                        } else {
                            $status = 'lain';
                        }
                    }
                    if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                        foreach ($izin as $i) {
                            if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                                $status = 'izin';
                                break;
                            }
                        }
                    }
                    if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                        foreach ($cuti as $c) {
                            if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                                $status = 'cuti';
                                break;
                            }
                        }
                    }
                    if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                        foreach ($sakit as $s) {
                            if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                                $status = 'sakit';
                                break;
                            }
                        }
                    }
                    if (!$status) {
                        $status = 'belum_presensi';
                    }
                    if (isset($rekapBulan[$status])) {
                        $rekapBulan[$status]++;
                    }
                }
            }
            $result[] = array_merge(['bulan' => $namaBulan[$bulan]], $rekapBulan);
        }
        return response()->json($result);
    }

    public function rekapBulananByPegawai(Request $request)
    {
        $pegawai_id = $request->query('pegawai_id');
        $tahun = $request->query('tahun', now('Asia/Jakarta')->year);
        $bulanSekarang = now('Asia/Jakarta')->month;
        $pegawai = \App\Models\MsPegawai::find($pegawai_id);
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }
        $result = [];
        $namaBulan = [
            1 => 'januari',
            2 => 'februari',
            3 => 'maret',
            4 => 'april',
            5 => 'mei',
            6 => 'juni',
            7 => 'juli',
            8 => 'agustus',
            9 => 'september',
            10 => 'oktober',
            11 => 'november',
            12 => 'desember'
        ];
        for ($bulan = 1; $bulan <= $bulanSekarang; $bulan++) {
            $rekapBulan = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'cuti' => 0,
                'tidak_hadir' => 0,
                'belum_presensi' => 0
            ];
            $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = $start->copy()->endOfMonth();
            $jumlahHari = $end->day;
            // Menggunakan format baru - 1 row per hari
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                ->orderBy('waktu_masuk')
                ->get();
            $izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            $cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            $sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            for ($hari = 1; $hari <= $jumlahHari; $hari++) {
                $tanggal = $start->copy()->day($hari)->format('Y-m-d');
                $status = null;
                // Menggunakan format baru - 1 row per hari
                $presensiHari = $presensi->where(fn($p) => $p->waktu_masuk->format('Y-m-d') === $tanggal);
                if ($presensiHari->count()) {
                    if ($presensiHari->where('status_presensi', 'hadir')->count()) {
                        $status = 'hadir';
                    } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                        $status = 'tidak_hadir';
                    } else {
                        $status = 'lain';
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($izin as $i) {
                        if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                            $status = 'izin';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($cuti as $c) {
                        if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                            $status = 'cuti';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($sakit as $s) {
                        if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                            $status = 'sakit';
                            break;
                        }
                    }
                }
                if (!$status) {
                    $status = 'belum_presensi';
                }
                if (isset($rekapBulan[$status])) {
                    $rekapBulan[$status]++;
                }
            }
            $result[] = array_merge(['bulan' => $namaBulan[$bulan]], $rekapBulan);
        }
        return response()->json($result);
    }

    /**
     * Hitung potongan penalty berdasarkan status presensi
     */
    private function calculatePenaltyAmount($presensi, $laukPaukUnit)
    {
        if (!$laukPaukUnit) {
            return 0;
        }

        $totalPenalty = 0;

        // Hitung potongan berdasarkan status masuk
        switch ($presensi->status_masuk) {
            case 'terlambat':
                // Hitung keterlambatan dalam menit relatif terhadap shift
                $penalty = $this->calculateTerlambatPenalty($presensi, $laukPaukUnit);
                $totalPenalty += $penalty;
                break;
            case 'tidak_absen_masuk':
                // Potongan untuk tidak masuk kerja tanpa izin
                $totalPenalty += $laukPaukUnit->pot_tanpa_izin;
                break;
            case 'izin':
                // Potongan untuk izin pribadi
                $totalPenalty += $laukPaukUnit->pot_izin_pribadi;
                break;
            case 'sakit':
                // Potongan untuk izin sakit
                $totalPenalty += $laukPaukUnit->pot_sakit;
                break;
        }

        // Hitung potongan berdasarkan status pulang
        switch ($presensi->status_pulang) {
            case 'pulang_awal':
                // Cek apakah ada keterangan untuk menentukan alasan
                if ($presensi->keterangan_pulang && !empty(trim($presensi->keterangan_pulang))) {
                    $totalPenalty += $laukPaukUnit->pot_pulang_awal_beralasan;
                } else {
                    $totalPenalty += $laukPaukUnit->pot_pulang_awal_tanpa_beralasan;
                }
                break;
        }

        return $totalPenalty;
    }

    /**
     * Hitung potongan terlambat berdasarkan rentang waktu
     */
    private function calculateTerlambatPenalty($presensi, $laukPaukUnit)
    {
        // Ambil shift detail untuk mendapatkan jam masuk dan toleransi
        $shiftDetail = $presensi->shiftDetail;
        if (!$shiftDetail) {
            return $laukPaukUnit->pot_terlambat_0806_0900; // Default penalty
        }

        // Ambil jam masuk shift untuk hari tersebut
        $hari = strtolower($presensi->waktu_masuk->locale('id')->isoFormat('dddd'));
        $jamMasukKey = $hari . '_masuk';
        $jamMasukShift = $shiftDetail->$jamMasukKey;
        
        if (!$jamMasukShift) {
            return $laukPaukUnit->pot_terlambat_0806_0900; // Default penalty
        }

        // Hitung batas waktu tidak terlambat
        $jamMasukCarbon = \Carbon\Carbon::createFromFormat('H:i', $jamMasukShift, 'Asia/Jakarta');
        $toleransi = $shiftDetail->toleransi_terlambat ?? 0;
        $batasTidakTerlambat = $jamMasukCarbon->copy()->addMinutes($toleransi);
        
        // Hitung waktu masuk pegawai
        $waktuMasukPegawai = $presensi->waktu_masuk;
        
        // Hitung selisih keterlambatan dalam menit
        $terlambatMenit = $waktuMasukPegawai->diffInMinutes($batasTidakTerlambat);
        
        // Mapping ke kategori potongan berdasarkan menit keterlambatan
        if ($terlambatMenit <= 54) {
            return $laukPaukUnit->pot_terlambat_0806_0900;
        } elseif ($terlambatMenit <= 60) {
            return $laukPaukUnit->pot_terlambat_0901_1000;
        } else {
            return $laukPaukUnit->pot_terlambat_setelah_1000;
        }
    }

    public function rekapPresensiBulananByAdminUnit(Request $request)
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

        $bulan = (int) $request->query('bulan', now('Asia/Jakarta')->month);
        $tahun = (int) $request->query('tahun', now('Asia/Jakarta')->year);
        $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
        $end = $start->copy()->endOfMonth();
        $hariLiburMap = [];
        $hariLiburAll = \App\Models\HariLibur::whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])->get();
        foreach ($hariLiburAll as $hl) {
            $hariLiburMap[$hl->unit_detail_id][$hl->tanggal->format('Y-m-d')] = true;
        }
        $result = [];
        $pegawais = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('unit_id', $unitId);
        })->with(['unitDetailPresensi'])->get();
        $no = 1;
        foreach ($pegawais as $pegawai) {
            // ... rest of the existing code remains the same ...
            $unitDetail = $pegawai->unitDetailPresensi;
            $unitDetailName = $unitDetail ? $unitDetail->name : null;
            $unitId = $unitDetail ? $unitDetail->unit_id : null;
            // Ambil nominal lauk pauk dari tabel lauk_pauk_unit
            $nominalLaukPauk = 0;
            $laukPaukUnit = null;
            if ($unitId) {
                $laukPaukUnit = \App\Models\LaukPaukUnit::where('unit_id', $unitId)->first();
                $nominalLaukPauk = $laukPaukUnit ? $laukPaukUnit->nominal : 0;
            }
            // Hitung hari efektif (tidak termasuk sabtu/minggu dan hari libur)
            $hariEfektif = 0;
            $jumlahLibur = 0;
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $isWeekend = in_array($date->dayOfWeek, [6, 0]); // 6=Sabtu, 0=Minggu
                $isLibur = isset($hariLiburMap[$pegawai->unit_detail_id_presensi][$date->format('Y-m-d')]);
                if ($isLibur) $jumlahLibur++;
                if (!$isWeekend && !$isLibur) $hariEfektif++;
            }
            // Ambil presensi pegawai di bulan tsb (format baru)
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                ->with(['shiftDetail'])
                ->get();
            $izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            $cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            $sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
                ->where('status', 'diterima')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('tanggal_mulai', [$start, $end])
                        ->orWhereBetween('tanggal_selesai', [$start, $end]);
                })->get();
            // Rekap presensi harian
            $rekap = [];
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $tanggal = $date->format('Y-m-d');
                $status = null;
                // Menggunakan format baru - 1 row per hari
                $presensiHari = $presensi->where(fn($p) => $p->waktu_masuk->format('Y-m-d') === $tanggal);
                if ($presensiHari->count()) {
                    if ($presensiHari->where('status_presensi', 'hadir')->count()) {
                        $status = 'hadir';
                    } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                        $status = 'tidak_hadir';
                    } else {
                        $status = 'lain';
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($izin as $i) {
                        if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                            $status = 'izin';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($cuti as $c) {
                        if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                            $status = 'cuti';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                    foreach ($sakit as $s) {
                        if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                            $status = 'sakit';
                            break;
                        }
                    }
                }
                if (!$status) {
                    $status = 'belum_presensi';
                }
                $rekap[$tanggal] = $status;
            }
            // Hitung jumlah presensi
            $jumlahHadir = collect($rekap)->where('hadir')->count();
            $jumlahIzin = collect($rekap)->where('izin')->count();
            $jumlahSakit = collect($rekap)->where('sakit')->count();
            $jumlahCuti = collect($rekap)->where('cuti')->count();
            $jumlahTidakHadir = collect($rekap)->where('tidak_hadir')->count();
            // Hitung presensi detail (format baru)
            $jumlahTerlambat = $presensi->where('status_masuk', 'terlambat')->count();
            $jumlahPulangAwal = $presensi->where('status_pulang', 'pulang_awal')->count();
            $jumlahJamDatangKosong = $presensi->whereIn('status_masuk', ['tidak_absen_masuk'])->count();
            $jumlahJamPulangKosong = $presensi->whereIn('status_pulang', ['tidak_absen_pulang'])->count();
            
            // Hitung total potongan penalty
            $totalPotongan = 0;
            foreach ($presensi as $p) {
                $potongan = $this->calculatePenaltyAmount($p, $laukPaukUnit);
                $totalPotongan += $potongan;
            }
            
            // Hitung nominal lauk pauk setelah potongan (hanya untuk perhitungan internal)
            $nominalLaukPaukSetelahPotongan = max(0, $nominalLaukPauk - $totalPotongan);
            
            $result[] = [
                'no' => $no++,
                'nik' => $pegawai->no_ktp,
                'nama_pegawai' => $pegawai->nama,
                'unit_kerja' => $unitDetailName,
                'hari_efektif' => $hariEfektif,
                'jumlah_hadir' => $jumlahHadir,
                'jumlah_izin' => $jumlahIzin,
                'jumlah_sakit' => $jumlahSakit,
                'jumlah_cuti' => $jumlahCuti,
                'jumlah_tidak_masuk' => $jumlahTidakHadir,
                'jumlah_dinas' => 0,
                'jumlah_terlambat' => $jumlahTerlambat,
                'jumlah_pulang_awal' => $jumlahPulangAwal,
                'jumlah_jam_datang_kosong' => $jumlahJamDatangKosong,
                'jumlah_jam_pulang_kosong' => $jumlahJamPulangKosong,
                'lembur' => 0,
                'jumlah_libur' => $jumlahLibur,
                'nominal_lauk_pauk' => $nominalLaukPauk // Response tetap sama, tidak berubah
            ];
        }
        return response()->json($result);
    }

    /**
     * Integrasikan pengajuan sakit, izin, cuti ke tabel presensi
     * Dipanggil ketika admin approve pengajuan
     */
    public function integratePengajuanToPresensi($pegawai_id, $jenis_pengajuan, $tanggal_mulai, $tanggal_selesai, $keterangan = null)
    {
        $pegawai = \App\Models\MsPegawai::find($pegawai_id);
        if (!$pegawai) {
            return false;
        }

        // Generate tanggal range
        $start = \Carbon\Carbon::parse($tanggal_mulai);
        $end = \Carbon\Carbon::parse($tanggal_selesai);
        
        // Loop setiap tanggal dalam range
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $tanggal = $date->format('Y-m-d');
            
            // Cek apakah sudah ada presensi di tanggal tersebut
            $existingPresensi = Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereDate('waktu_masuk', $tanggal)
                ->first();
            
            if ($existingPresensi) {
                // Update presensi yang sudah ada
                $existingPresensi->update([
                    'status_masuk' => $jenis_pengajuan,
                    'status_presensi' => $jenis_pengajuan,
                    'keterangan_masuk' => $keterangan ?? "Pengajuan {$jenis_pengajuan} yang disetujui",
                ]);
            } else {
                // Buat presensi baru
                Presensi::create([
                    'no_ktp' => $pegawai->no_ktp,
                    'shift_id' => $pegawai->shift_detail_id ? \App\Models\ShiftDetail::find($pegawai->shift_detail_id)->shift_id : null,
                    'shift_detail_id' => $pegawai->shift_detail_id,
                    'waktu_masuk' => $date->setTime(8, 0, 0), // Default jam 8 pagi
                    'status_masuk' => $jenis_pengajuan,
                    'lokasi_masuk' => null,
                    'keterangan_masuk' => $keterangan ?? "Pengajuan {$jenis_pengajuan} yang disetujui",
                    'status_presensi' => $jenis_pengajuan,
                    // Backward compatibility
                    //'waktu' => $date->setTime(8, 0, 0),
                    //'status' => $jenis_pengajuan,
                    //'lokasi' => null,
                    //'keterangan' => $keterangan ?? "Pengajuan {$jenis_pengajuan} yang disetujui",
                ]);
            }
        }
        
        return true;
    }

    /**
     * Hapus integrasi pengajuan dari presensi (ketika pengajuan ditolak/dibatalkan)
     */
    public function removePengajuanFromPresensi($pegawai_id, $jenis_pengajuan, $tanggal_mulai, $tanggal_selesai)
    {
        $pegawai = \App\Models\MsPegawai::find($pegawai_id);
        if (!$pegawai) {
            return false;
        }

        $start = \Carbon\Carbon::parse($tanggal_mulai);
        $end = \Carbon\Carbon::parse($tanggal_selesai);
        
        // Hapus presensi yang dibuat dari pengajuan
        Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->where('status_masuk', $jenis_pengajuan)
            ->where('keterangan_masuk', 'like', "Pengajuan {$jenis_pengajuan} yang disetujui%")
            ->delete();
            
        return true;
    }

    public function getLaporanKehadiranKaryawan(Request $request, $pegawai_id)
    {
        Carbon::setLocale('id');
        $pegawai = MsPegawai::where('id', $pegawai_id)->firstOrFail();
        $noKtp = $pegawai->no_ktp;

        // Ambil presensi bulan dan tahun dari request atau default bulan ini
        $bulan = $request->get('bulan', now()->month);
        $tahun = $request->get('tahun', now()->year);


        $presensiList = Presensi::where('no_ktp', $noKtp)
            ->whereMonth('waktu_masuk', $bulan)
            ->whereYear('waktu_masuk', $tahun)
            ->orderBy('waktu_masuk')
            ->get();

        $data = [];

        foreach ($presensiList as $p) {
            // Ambil tanggal presensi
            $tanggalPresensi = $p->waktu_masuk
                ? Carbon::parse($p->waktu_masuk)->toDateString()
                : Carbon::parse($p->waktu_pulang)->toDateString();

            // Ambil shift detail pegawai
            $shiftDetail = ShiftDetail::find($pegawai->shift_detail_id);

            // Tentukan nama hari (lowercase) untuk mapping kolom shift
            $hari = strtolower(Carbon::parse($tanggalPresensi)->locale('id')->isoFormat('dddd'));
            // Sesuaikan format kolom (misal: "senin_masuk", "senin_pulang")
            $kolomMasuk = "{$hari}_masuk";
            $kolomPulang = "{$hari}_pulang";

            // Jam kerja sesuai shift + tanggal presensi
            $jamKerjaMasuk = Carbon::parse($tanggalPresensi . ' ' . $shiftDetail->$kolomMasuk);
            $jamKerjaPulang = Carbon::parse($tanggalPresensi . ' ' . $shiftDetail->$kolomPulang);

            // Jam presensi masuk/pulang
            $jamMasuk = $p->waktu_masuk ? Carbon::parse($p->waktu_masuk) : null;
            $jamKeluar = $p->waktu_pulang ? Carbon::parse($p->waktu_pulang) : null;

            // Hitung datang cepat / telat
            $menitCepat = $menitTelat = 0;
            if ($jamMasuk) {
                $diff = $jamKerjaMasuk->diffInMinutes($jamMasuk, false);
                if ($diff > 0) {
                    $menitCepat = $diff; // datang cepat
                } elseif ($diff < 0) {
                    $menitTelat = abs($diff); // telat
                }
            }

            // Hitung pulang cepat / lembur
            $menitPulangCepat = $menitLembur = 0;
            if ($jamKeluar) {
                // Selisih menit: jamKeluar - jamKerjaPulang
                $diffPulang = $jamKeluar->diffInMinutes($jamKerjaPulang, false);

                if ($diffPulang > 0) {
                    // Keluar sebelum jam kerja pulang → pulang cepat
                    $menitPulangCepat = abs($diffPulang);
                } elseif ($diffPulang < 0) {
                    // Keluar setelah jam kerja pulang → lembur
                    $menitLembur = abs($diffPulang);
                }
            }


            // Hitung total jam kerja
            $jamKerjaTotal = 0;
            if ($jamMasuk && $jamKeluar) {
                $jamKerjaTotal = round($jamMasuk->floatDiffInHours($jamKeluar), 2);
            }

            $data[] = [
                'tgl_absensi' => $jamMasuk ? $jamMasuk->translatedFormat('l, j F Y') : null,
                'jam_kerja' => [
                    'masuk' => $jamKerjaMasuk->format('H:i'),
                    'pulang' => $jamKerjaPulang->format('H:i'),
                ],
                'jam_masuk' => $jamMasuk ? $jamMasuk->format('H:i') : '-',
                'jam_keluar' => $jamKeluar ? $jamKeluar->format('H:i') : '-',
                'jumlah_menit_datang' => [
                    'menit_datang_cepat' => $menitCepat,
                    'menit_telat' => $menitTelat,
                ],
                'jumlah_menit_pulang' => [
                    'menit_pulang_cepat' => $menitPulangCepat,
                    'menit_lembur' => $menitLembur,
                ],
                'jam_kerja_total' => $jamKerjaTotal,
                'alasan' => $p->keterangan_masuk ?: ($p->keterangan_pulang ?: ''),
            ];
        }

        return response()->json([
            'pegawai' => [
                'no_ktp' => $pegawai->no_ktp,
                'nama' => $pegawai->nama,
                'unit_kerja' => $pegawai->unit ? $pegawai->unit->nama : null,
                'jabatan' => $pegawai->jabatan
            ],
            'periode' => [
                'bulan' => $bulan,
                'tahun' => $tahun,
            ],
            'data' => $data
        ]);
    }
}