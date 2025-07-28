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

        // Jika hari libur, otomatis set status hadir
        // if ($isHariLibur) {
        //     $status = 'hadir_hari_libur';
        //     $keterangan = 'Hari Libur';

        //     // Cek apakah sudah ada presensi hari ini
        //     $presensiHariIni = Presensi::where('no_ktp', $pegawai->no_ktp)
        //         ->whereDate('waktu', $now->toDateString())
        //         ->get();

        //     if ($presensiHariIni->where('status', 'hadir_hari_libur')->count() > 0) {
        //         return response()->json(['message' => 'Sudah ada presensi hari libur untuk hari ini'], 400);
        //     }

        //     // Simpan presensi hari libur
        //     $presensi = Presensi::create([
        //         'no_ktp' => $pegawai->no_ktp,
        //         'shift_id' => $shiftDetail->shift_id,
        //         'shift_detail_id' => $shiftDetail->id,
        //         'waktu' => $now,
        //         'status' => $status,
        //         'lokasi' => $request->lokasi,
        //         'keterangan' => $keterangan,
        //     ]);

        //     $shift_name = $shiftDetail->shift ? $shiftDetail->shift->name : null;
        //     return response()->json([
        //         'no_ktp' => $presensi->no_ktp,
        //         'shift_name' => $shift_name,
        //         'shift_detail_id' => $presensi->shift_detail_id,
        //         'tanggal' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d'),
        //         'waktu' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
        //         'status' => $presensi->status,
        //         'lokasi' => $presensi->lokasi,
        //         'keterangan' => $presensi->keterangan,
        //         'updated_at' => $presensi->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
        //         'created_at' => $presensi->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
        //         'id' => $presensi->id,
        //     ]);
        // }

        if (!$jamMasuk && !$jamPulang) {
            return response()->json(['message' => 'Hari ini libur, tidak ada jam masuk/pulang'], 400);
        }
        $waktuSekarang = $now->format('H:i');
        $status = null;
        $keterangan = null;
        // Cek apakah sudah ada absen masuk hari ini
        $presensiHariIni = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu', $now->toDateString())
            ->get();
        $sudahAbsenMasuk = $presensiHariIni->whereIn('status', ['absen_masuk', 'terlambat'])->count() > 0;
        $jam12 = \Carbon\Carbon::createFromTime(12, 0, 0, 'Asia/Jakarta');
        // Jika presensi dilakukan setelah jam 12.00 dan belum absen masuk
        if (!$sudahAbsenMasuk && $now->greaterThanOrEqualTo($jam12)) {
            // Set waktu presensi tidak_absen_masuk ke jam masuk shift
            $waktuMasuk = null;
            try {
                $waktuMasuk = \Carbon\Carbon::createFromFormat('H:i', $jamMasuk, 'Asia/Jakarta')->setDate($now->year, $now->month, $now->day);
            } catch (\Exception $e) {
                $waktuMasuk = $now->copy()->setTime(0, 0, 0); // fallback ke 00:00 jika format error
            }
            Presensi::create([
                'no_ktp' => $pegawai->no_ktp,
                'shift_id' => $shiftDetail->shift_id,
                'shift_detail_id' => $shiftDetail->id,
                'waktu' => $waktuMasuk,
                'status' => 'tidak_absen_masuk',
                'status_presensi' => 'tidak_hadir',
                'lokasi' => $request->lokasi,
                'keterangan' => 'Tidak absen masuk, sudah lewat jam 12:00',
            ]);
            // Insert presensi absen pulang/keluar pada jam presensi
            $status = null;
            $keterangan = null;
            // Cek absen pulang
            if ($jamPulang) {
                try {
                    $waktuPulang = \Carbon\Carbon::createFromFormat('H:i', $jamPulang, 'Asia/Jakarta');
                    $batasPulang = $waktuPulang->copy()->subMinutes($tolPulang);
                    if ($now->lessThan($batasPulang)) {
                        $status = 'pulang_awal';
                        $keterangan = 'Pulang sebelum waktu pulang';
                    } else {
                        $status = 'absen_pulang';
                    }
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Format jam pulang tidak valid'], 400);
                }
            }
            $presensi = Presensi::create([
                'no_ktp' => $pegawai->no_ktp,
                'shift_id' => $shiftDetail->shift_id,
                'shift_detail_id' => $shiftDetail->id,
                'waktu' => $now,
                'status' => $status,
                'status_presensi' => in_array($status, [
                    'absen_masuk',
                    'terlambat',
                    'absen_pulang',
                    'pulang_awal',
                    //'hadir_hari_libur'
                ]) ? 'hadir' : 'tidak_hadir',
                'lokasi' => $request->lokasi,
                'keterangan' => $keterangan,
            ]);
            $shift_name = $shiftDetail->shift ? $shiftDetail->shift->name : null;
            return response()->json([
                'no_ktp' => $presensi->no_ktp,
                'shift_name' => $shift_name,
                'shift_detail_id' => $presensi->shift_detail_id,
                'tanggal' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d'),
                'waktu' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
                'status' => $presensi->status,
                'lokasi' => $presensi->lokasi,
                'keterangan' => $presensi->keterangan,
                'updated_at' => $presensi->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                'created_at' => $presensi->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
                'id' => $presensi->id,
            ]);
        }
        // Cek absen masuk
        if (!$sudahAbsenMasuk && $jamMasuk) {
            if ($now->greaterThanOrEqualTo($jam12)) {
                $status = 'tidak_absen_masuk';
                $keterangan = 'Tidak absen masuk, sudah lewat jam 12:00';
            } else {
                try {
                    $waktuMasuk = \Carbon\Carbon::createFromFormat('H:i', $jamMasuk, 'Asia/Jakarta');
                    $batasMasuk = $waktuMasuk->copy()->addMinutes($tolMasuk);
                    if ($now->lessThan($waktuMasuk)) {
                        return response()->json(['message' => 'Belum waktunya absen masuk'], 400);
                    }
                    if ($now->between($waktuMasuk, $batasMasuk)) {
                        $status = 'absen_masuk';
                    } elseif ($now->greaterThan($batasMasuk) && $now->lessThan($jam12)) {
                        $status = 'terlambat';
                        $keterangan = 'Terlambat absen masuk';
                    }
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Format jam masuk tidak valid'], 400);
                }
            }
        }
        // Cek absen pulang
        if ($jamPulang && !$status) {
            try {
                $waktuPulang = \Carbon\Carbon::createFromFormat('H:i', $jamPulang, 'Asia/Jakarta');
                $batasPulang = $waktuPulang->copy()->subMinutes($tolPulang);
                if ($now->lessThan($batasPulang)) {
                    return response()->json(['message' => 'Belum waktunya absen pulang'], 400);
                }
                if ($now->greaterThanOrEqualTo($batasPulang)) {
                    $status = 'pulang_awal';
                    $keterangan = 'Pulang sebelum waktu pulang';
                } else {
                    $status = 'absen_pulang';
                }
            } catch (\Exception $e) {
                return response()->json(['message' => 'Format jam pulang tidak valid'], 400);
            }
        }
        if (!$status) {
            $status = 'tidak_masuk';
            $keterangan = 'Tidak absen masuk atau pulang';
        }
        // Simpan presensi
        $presensi = Presensi::create([
            'no_ktp' => $pegawai->no_ktp,
            'shift_id' => $shiftDetail->shift_id,
            'shift_detail_id' => $shiftDetail->id,
            'waktu' => $now,
            'status' => $status,
            'status_presensi' => in_array($status, [
                'absen_masuk',
                'terlambat',
                'absen_pulang',
                'pulang_awal',
                'hadir_hari_libur'
            ]) ? 'hadir' : 'tidak_hadir',
            'lokasi' => $request->lokasi,
            'keterangan' => $keterangan,
        ]);
        // Ambil shift_name
        $shift_name = $shiftDetail->shift ? $shiftDetail->shift->name : null;
        // Format response custom
        return response()->json([
            'no_ktp' => $presensi->no_ktp,
            'shift_name' => $shift_name,
            'shift_detail_id' => $presensi->shift_detail_id,
            'tanggal' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d'),
            'waktu' => $presensi->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s'),
            'status' => $presensi->status,
            'lokasi' => $presensi->lokasi,
            'keterangan' => $presensi->keterangan,
            'updated_at' => $presensi->updated_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
            'created_at' => $presensi->created_at->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s'),
            'id' => $presensi->id,
        ]);
    }

    // Presensi hari ini (masuk & keluar)
    public function today(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }
        $today = \Carbon\Carbon::now('Asia/Jakarta')->toDateString();
        $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu', $today)
            ->orderBy('waktu')
            ->get();
        $masuk = $presensi->whereIn('status', ['absen_masuk', 'terlambat', 'hadir_hari_libur'])->first();
        if (!$masuk) {
            $masuk = $presensi->whereIn('status', ['tidak_absen_masuk', 'tidak_masuk'])->first();
        }
        $keluar = $presensi->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
        if (!$keluar) {
            $keluar = $presensi->where('status', 'tidak_masuk')->last();
        }
        return response()->json([
            'tanggal' => $today,
            'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'status_masuk' => $masuk ? $masuk->status : null,
            'status_keluar' => $keluar ? $keluar->status : null,
            'status_presensi' => $masuk ? $masuk->status_presensi : null,
            'lokasi_masuk' => $masuk ? $masuk->lokasi : null,
            'lokasi_keluar' => $keluar ? $keluar->lokasi : null,
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
            $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereDate('waktu', $tanggal)
                ->orderBy('waktu')
                ->get();
            $masuk = $presensi->whereIn('status', ['absen_masuk', 'terlambat', 'hadir_hari_libur'])->first();
            if (!$masuk) {
                $masuk = $presensi->whereIn('status', ['tidak_absen_masuk', 'tidak_masuk'])->first();
            }
            $keluar = $presensi->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
            if (!$keluar) {
                $keluar = $presensi->where('status', 'tidak_masuk')->last();
            }
            return response()->json([
                'hari' => \Carbon\Carbon::parse($tanggal)->locale('id')->isoFormat('dddd'),
                'tanggal' => $tanggal,
                'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'status_masuk' => $masuk ? $masuk->status : null,
                'status_keluar' => $keluar ? $keluar->status : null,
                'status_presensi' => $masuk ? $masuk->status_presensi : null,
            ]);
        }
        $from = $request->query('from', \Carbon\Carbon::now('Asia/Jakarta')->startOfMonth()->toDateString());
        $to = $request->query('to', \Carbon\Carbon::now('Asia/Jakarta')->toDateString());
        $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween(DB::raw('DATE(waktu)'), [$from, $to])
            ->orderBy('waktu')
            ->get()
            ->groupBy(function ($item) {
                return $item->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->toDateString();
            });
        $history = [];
        foreach ($presensi as $tanggal => $items) {
            $masuk = $items->whereIn('status', ['absen_masuk', 'terlambat'])->first();
            if (!$masuk) {
                $masuk = $items->whereIn('status', ['tidak_absen_masuk', 'tidak_masuk'])->first();
            }
            $keluar = $items->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
            if (!$keluar) {
                $keluar = $items->where('status', 'tidak_masuk')->last();
            }
            $history[] = [
                'hari' => \Carbon\Carbon::parse($tanggal)->locale('id')->isoFormat('dddd'),
                'tanggal' => $tanggal,
                'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'status_masuk' => $masuk ? $masuk->status : null,
                'status_keluar' => $keluar ? $keluar->status : null,
                'status_presensi' => $masuk ? $masuk->status_presensi : null,
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
                $query->whereDate('waktu', $tanggal);
            }
            $presensis = $query->get();
            $total_hadir = $presensis->whereIn('status', ['absen_masuk', 'absen_pulang', 'terlambat', 'hadir_hari_libur'])->count();
            $total_tidak_masuk = $presensis->where('status', 'tidak_masuk')->count();

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
        $query = Presensi::whereIn('no_ktp', $no_ktps);
        if ($tanggal) {
            $query->whereDate('waktu', $tanggal);
        }
        $presensis = $query->orderBy('waktu', 'desc')->get();
        $result = $presensis->map(function ($p) use ($pegawaiMap) {
            $pegawai = $pegawaiMap[$p->no_ktp] ?? null;
            return [
                'id' => $p->id,
                'no_ktp' => $p->no_ktp,
                'nama' => $pegawai ? $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : '') : null,
                'status' => $p->status,
                'waktu' => $p->waktu,
                'keterangan' => $p->keterangan,
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

        // Ambil presensi pegawai di bulan tsb
        $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween('waktu', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->orderBy('waktu')
            ->get();

        // Ambil pengajuan izin, cuti, sakit yang diterima di bulan tsb
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

        // Rekap per tanggal (1 hari hanya 1 status, prioritas: hadir > izin > sakit > cuti > tidak hadir > lain > belum presensi)
        $rekap = [];
        foreach ($tanggalList as $tanggal) {
            $status = null;
            // Cek presensi (hadir/tidak hadir/lain)
            $presensiHari = $presensi->where(fn($p) => $p->waktu->format('Y-m-d') === $tanggal);
            if ($presensiHari->count()) {
                // Prioritas hadir
                if ($presensiHari->where('status_presensi', 'hadir')->count()) {
                    $status = 'hadir';
                } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                    $status = 'tidak_hadir';
                } else {
                    $status = 'lain';
                }
            }
            // Cek izin
            if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                foreach ($izin as $i) {
                    if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                        $status = 'izin';
                        break;
                    }
                }
            }
            // Cek cuti
            if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                foreach ($cuti as $c) {
                    if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                        $status = 'cuti';
                        break;
                    }
                }
            }
            // Cek sakit
            if (!$status || $status === 'tidak_hadir' || $status === 'lain') {
                foreach ($sakit as $s) {
                    if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                        $status = 'sakit';
                        break;
                    }
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

        // Ambil semua pegawai di unit detail tsb
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
                $presensiQuery->whereDate('waktu', '>=', $from);
            }
            if ($to) {
                $presensiQuery->whereDate('waktu', '<=', $to);
            }
            $presensi = $presensiQuery->orderBy('waktu', 'desc')->get();
            $result[] = [
                'pegawai' => [
                    'id' => $pegawai->id,
                    'nama' => $pegawai->nama_depan . ($pegawai->nama_belakang ? ' ' . $pegawai->nama_belakang : ''),
                    'no_ktp' => $pegawai->no_ktp,
                    'unit_detail_id_presensi' => $pegawai->unit_detail_id_presensi,
                ],
                'presensi' => $presensi
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
        $presensis = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu', $tanggal)
            ->get();
        if ($presensis->isEmpty()) {
            return response()->json(['message' => 'Tidak ada presensi pada tanggal tersebut'], 404);
        }
        $updated = [];
        foreach ($updates as $update) {
            $status = $update['status'] ?? null;
            $waktu = $update['waktu'] ?? null;
            foreach ($presensis as $presensi) {
                // Update presensi yang status sama (atau update semua jika status tidak dikirim)
                if (!$status || $presensi->status === $status) {
                    $presensi->update(array_filter($update, fn($v) => $v !== null));
                    $updated[] = $presensi;
                }
            }
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
                // Ambil presensi pegawai di bulan tsb
                $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                    ->whereBetween('waktu', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                    ->orderBy('waktu')
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
                    $presensiHari = $presensi->where(fn($p) => $p->waktu->format('Y-m-d') === $tanggal);
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
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                ->orderBy('waktu')
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
                $presensiHari = $presensi->where(fn($p) => $p->waktu->format('Y-m-d') === $tanggal);
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
            // Ambil presensi pegawai di bulan tsb
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
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
                $presensiHari = $presensi->where(fn($p) => $p->waktu->format('Y-m-d') === $tanggal);
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
            // Hitung presensi detail
            $jumlahTerlambat = $presensi->where('status', 'terlambat')->count();
            $jumlahPulangAwal = $presensi->where('status', 'pulang_awal')->count();
            $jumlahJamDatangKosong = $presensi->whereIn('status', ['tidak_absen_masuk', 'tidak_masuk'])->count();
            $jumlahJamPulangKosong = $presensi->whereIn('status', ['tidak_absen_pulang', 'tidak_masuk'])->count();
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
}
