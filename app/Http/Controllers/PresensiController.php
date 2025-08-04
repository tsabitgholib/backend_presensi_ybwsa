<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Presensi;
use App\Models\MsPegawai;
use App\Models\Shift;
use App\Models\ShiftDetail;
use App\Models\UnitDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            $statusMasuk = 'tidak_masuk';
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
            'waktu' => $waktuMasukUntukSimpan,
            'status' => $statusMasuk,
            'lokasi' => $request->lokasi,
            'keterangan' => $keteranganMasuk,
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
        // Jika salah satu hadir, maka status akhir adalah hadir
        $hadirStatuses = ['absen_masuk', 'terlambat', 'absen_pulang', 'pulang_awal'];
        
        if (in_array($statusMasuk, $hadirStatuses) || in_array($statusPulang, $hadirStatuses)) {
            return 'hadir';
        }
        
        return 'tidak_hadir';
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $tanggal = $request->query('tanggal');
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin) {
            $q->where('unit_id', $admin->unit_id);
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
                'nama' => $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : ''),
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $tanggal = $request->query('tanggal');
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin) {
            $q->where('unit_id', $admin->unit_id);
        })->get(['id', 'no_ktp', 'nama_depan', 'nama_belakang']);
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
                'nama' => $pegawai ? $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : '') : null,
                'status_masuk' => $p->status_masuk,
                'status_pulang' => $p->status_pulang,
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
     * Rekap history presensi pegawai per bulan (pegawai yang login)
     */
    public function rekapHistoryBulananPegawai(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $bulan = $request->query('bulan', now('Asia/Jakarta')->month);
        $tahun = $request->query('tahun', now('Asia/Jakarta')->year);

        // Ambil semua tanggal di bulan tsb
        $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
        $end = $start->copy()->endOfMonth();
        $tanggalList = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $tanggalList[] = $date->format('Y-m-d');
        }

        // Ambil presensi pegawai di bulan tsb (format baru) - termasuk sakit, izin, cuti
        $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->orderBy('waktu_masuk')
            ->get();

        // Rekap per tanggal (1 hari hanya 1 status, prioritas: hadir > izin > sakit > cuti > tidak hadir > lain > belum presensi)
        $rekap = [];
        foreach ($tanggalList as $tanggal) {
            $status = null;
            // Cek presensi (hadir/tidak hadir/izin/sakit/cuti) - format baru
            $presensiHari = $presensi->where(fn($p) => $p->waktu_masuk->format('Y-m-d') === $tanggal);
            if ($presensiHari->count()) {
                $presensiRecord = $presensiHari->first();
                // Ambil status dari kolom status_presensi atau status_masuk
                if ($presensiRecord->status_presensi === 'hadir') {
                    $status = 'hadir';
                } elseif ($presensiRecord->status_presensi === 'tidak_hadir') {
                    $status = 'tidak_hadir';
                } elseif (in_array($presensiRecord->status_masuk, ['izin', 'sakit', 'cuti'])) {
                    $status = $presensiRecord->status_masuk;
                } else {
                    $status = 'lain';
                }
            }
            
            // Jika belum ada status
            if (!$status) {
                $status = 'belum_presensi';
            }
            $rekap[$tanggal] = $status;
        }

        // Hitung jumlah per status dan kumpulkan tanggalnya
        $result = [
            'hadir' => 0,
            'izin' => 0,
            'sakit' => 0,
            'cuti' => 0,
            'tidak_hadir' => 0,
            'belum_presensi' => 0,
            'tanggal_hadir' => [],
            'tanggal_izin' => [],
            'tanggal_sakit' => [],
            'tanggal_cuti' => [],
            'tanggal_tidak_hadir' => [],
            'tanggal_belum_presensi' => [],
        ];
        foreach ($rekap as $tanggal => $status) {
            $tgl = date('d', strtotime($tanggal));
            if (isset($result[$status])) {
                $result[$status]++;
                $result['tanggal_' . $status][] = $tgl;
            }
        }
        $result['bulan'] = $bulan;
        $result['tahun'] = $tahun;
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $unit_detail_id = $request->query('unit_detail_id');
        $pegawai_id = $request->query('pegawai_id');
        $from = $request->query('from');
        $to = $request->query('to');

        // Ambil semua pegawai di unit admin (jika unit_detail_id tidak diisi, ambil semua di unit admin)
        $pegawaiQuery = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin, $unit_detail_id) {
            $q->where('unit_id', $admin->unit_id);
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
                    'masuk' => [
                        'id' => $p->id,
                        'waktu' => $p->waktu_masuk->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
                        'status' => $p->status_masuk,
                        'status_presensi' => $p->status_presensi,
                        'lokasi' => $p->lokasi_masuk,
                        'keterangan' => $p->keterangan_masuk,
                        'created_at' => $p->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                        'updated_at' => $p->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                    ],
                    'pulang' => $p->waktu_pulang ? [
                        'id' => $p->id,
                        'waktu' => $p->waktu_pulang->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
                        'status' => $p->status_pulang,
                        'status_presensi' => $p->status_presensi,
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

            // Hitung status presensi keseluruhan (jika masuk dan pulang sama-sama hadir)
            $statusPresensiKeseluruhan = 'tidak_hadir';
            $totalHadir = 0;
            $totalPresensi = 0;

            foreach ($presensiBerpasangan as $presensi) {
                if ($presensi['masuk'] && $presensi['masuk']['status_presensi'] === 'hadir') {
                    $totalHadir++;
                }
                if ($presensi['pulang'] && $presensi['pulang']['status_presensi'] === 'hadir') {
                    $totalHadir++;
                }
                if ($presensi['masuk']) $totalPresensi++;
                if ($presensi['pulang']) $totalPresensi++;
            }

            if ($totalPresensi > 0) {
                $persentaseHadir = ($totalHadir / $totalPresensi) * 100;
                if ($persentaseHadir >= 80) {
                    $statusPresensiKeseluruhan = 'hadir';
                } elseif ($persentaseHadir >= 50) {
                    $statusPresensiKeseluruhan = 'cukup';
                } else {
                    $statusPresensiKeseluruhan = 'tidak_hadir';
                }
            }

            $result[] = [
                'pegawai' => [
                    'id' => $pegawai->id,
                    'no_ktp' => $pegawai->no_ktp,
                    'nama' => $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : ''),
                    'unit_detail_name' => $unitDetailName,
                ],
                'status_presensi_keseluruhan' => $statusPresensiKeseluruhan,
                'total_hadir' => $totalHadir,
                'total_presensi' => $totalPresensi,
                'persentase_hadir' => $totalPresensi > 0 ? round(($totalHadir / $totalPresensi) * 100, 2) : 0,
                'presensi' => $presensiBerpasangan,
            ];
        }
        return response()->json($result);
    }

    /**
     * Update 2 presensi (masuk & keluar) pegawai pada tanggal yang sama oleh admin unit
     * pegawai_id dan tanggal di parameter
     */
    public function updatePresensiByAdminUnitBulk(Request $request, $pegawai_id, $tanggal)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
        $updates = $request->input('updates');
        if (!$pegawai_id || !$tanggal || !is_array($updates)) {
            return response()->json(['message' => 'pegawai_id, tanggal, dan updates wajib diisi'], 422);
        }
        $pegawai = \App\Models\MsPegawai::find($pegawai_id);
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }
        // Validasi pegawai milik unit admin
        if (!$pegawai->unitDetailPresensi || $pegawai->unitDetailPresensi->unit_id != $admin->unit_id) {
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
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
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
            $pegawais = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin) {
                $q->where('unit_id', $admin->unit_id);
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

    public function rekapPresensiBulananByAdminUnit(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin || $admin->role !== 'admin_unit') {
            return response()->json(['message' => 'Hanya admin unit yang boleh mengakses.'], 403);
        }
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
        $pegawais = \App\Models\MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($admin) {
            $q->where('unit_id', $admin->unit_id);
        })->with(['unitDetailPresensi'])->get();
        $no = 1;
        foreach ($pegawais as $pegawai) {
            $unitDetail = $pegawai->unitDetailPresensi;
            $unitDetailName = $unitDetail ? $unitDetail->name : null;
            $unitId = $unitDetail ? $unitDetail->unit_id : null;
            // Ambil nominal lauk pauk dari tabel lauk_pauk_unit
            $nominalLaukPauk = 0;
            if ($unitId) {
                $laukPauk = \App\Models\LaukPaukUnit::where('unit_id', $unitId)->first();
                $nominalLaukPauk = $laukPauk ? $laukPauk->nominal : 0;
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
            $jumlahJamDatangKosong = $presensi->whereIn('status_masuk', ['tidak_absen_masuk', 'tidak_masuk'])->count();
            $jumlahJamPulangKosong = $presensi->whereIn('status_pulang', ['tidak_absen_pulang', 'tidak_masuk'])->count();
            $result[] = [
                'no' => $no++,
                'nik' => $pegawai->no_ktp,
                'nama_pegawai' => trim($pegawai->nama_depan . ' ' . ($pegawai->nama_tengah ?? '') . ' ' . ($pegawai->nama_belakang ?? '')),
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
                'nominal_lauk_pauk' => $nominalLaukPauk
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
                    'waktu' => $date->setTime(8, 0, 0),
                    'status' => $jenis_pengajuan,
                    'lokasi' => null,
                    'keterangan' => $keterangan ?? "Pengajuan {$jenis_pengajuan} yang disetujui",
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
}