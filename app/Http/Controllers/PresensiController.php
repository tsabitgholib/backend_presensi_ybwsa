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
use App\Models\PresensiJadwalDinas;
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

    /**
     * Cek apakah pegawai memiliki jadwal dinas pada tanggal tertentu
     */
    private function checkJadwalDinas($pegawaiId, $tanggal)
    {
        return PresensiJadwalDinas::getJadwalDinasForPegawai($pegawaiId, $tanggal);
    }

    public function store(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        // Load relasi yang diperlukan untuk validasi
        $pegawai->load([
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit',
            'pegawai'
        ]);

        $request->validate([
            'lokasi' => 'required|array|size:2',
        ]);
        $now = \Carbon\Carbon::now('Asia/Jakarta');
        $hari = strtolower($now->locale('id')->isoFormat('dddd'));

        // Cek apakah pegawai memiliki jadwal dinas hari ini
        $jadwalDinas = $this->checkJadwalDinas($pegawai->id, $now->toDateString());

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
        // if (!$this->isPointInPolygon($request->lokasi, $polygon)) {
        //     return response()->json(['message' => 'Lokasi di luar area'], 400);
        // }

        // Cek apakah hari ini adalah hari libur
        $isHariLibur = \App\Models\HariLibur::isHariLibur($unitDetail->id, $now->toDateString());
        if ($isHariLibur) {
            return response()->json(['message' => 'Hari ini adalah hari libur'], 400);
        }

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
            return $this->handlePresensiPulang($request, $presensiHariIni, $now, $shiftDetail, $jamPulang, $tolPulang, $jadwalDinas);
        } else {
            // CREATE: Presensi masuk
            return $this->handlePresensiMasuk($request, $now, $shiftDetail, $jamMasuk, $tolMasuk, $pegawai, $jadwalDinas);
        }
    }

    private function handlePresensiMasuk(Request $request, $now, $shiftDetail, $jamMasuk, $tolMasuk, $pegawai, $jadwalDinas = null)
    {
        $statusMasuk = null;
        $keteranganMasuk = null;
        $waktuMasukUntukSimpan = $now; // Default waktu presensi
        $jam12 = \Carbon\Carbon::createFromTime(12, 0, 0, 'Asia/Jakarta');

        // Ambil jam pulang untuk kebutuhan pulang_awal
        $jamPulang = $shiftDetail->{$now->locale('id')->isoFormat('dddd') . '_pulang'} ?? null;

        // Jika presensi dilakukan setelah jam 12.00 tapi sebelum jam pulang
        if ($now->greaterThanOrEqualTo($jam12) && $jamPulang && $now->lessThan(\Carbon\Carbon::createFromFormat('H:i', $jamPulang, 'Asia/Jakarta'))) {
            // Anggap tidak absen masuk
            $statusMasuk = 'tidak_absen_masuk';
            $keteranganMasuk = 'Tidak absen masuk, sudah lewat jam 12:00';
            $waktuMasukUntukSimpan = $jam12;

            // Simpan presensi dengan status masuk gagal
            $presensi = Presensi::create([
                'no_ktp' => $pegawai->no_ktp,
                'shift_id' => $shiftDetail->shift_id,
                'shift_detail_id' => $shiftDetail->id,
                'waktu_masuk' => $waktuMasukUntukSimpan,
                'status_masuk' => $statusMasuk,
                'lokasi_masuk' => $request->lokasi,
                'keterangan_masuk' => $keteranganMasuk,
                'status_presensi' => 'tidak_hadir',
            ]);

            // Otomatis dianggap langsung absen pulang (pulang_awal)
            $presensi->update([
                'waktu_pulang' => $now,
                'status_pulang' => 'pulang_awal',
                'lokasi_pulang' => $request->lokasi,
                'keterangan_pulang' => 'Absen pulang setelah jam 12 tanpa absen masuk',
                'status_presensi' => 'tidak_hadir',
            ]);

            return response()->json([
                'no_ktp' => $presensi->no_ktp,
                'shift_detail_id' => $presensi->shift_detail_id,
                'tanggal' => $presensi->waktu_masuk->format('Y-m-d'),
                'waktu_masuk' => $presensi->waktu_masuk->format('H:i:s'),
                'status_masuk' => $presensi->status_masuk,
                'waktu_pulang' => $presensi->waktu_pulang->format('H:i:s'),
                'status_pulang' => $presensi->status_pulang,
                'keterangan' => $presensi->keterangan_pulang,
            ]);
        }

        // logic presensi
        if ($pegawai->pegawai->profesi == 'driver' || $jadwalDinas) {
            $statusMasuk = 'absen_masuk';
            $keteranganMasuk = $jadwalDinas ? $jadwalDinas->keterangan : 'Otomatis absen masuk';
            $waktuMasukUntukSimpan = $now;
        } else {
            if ($jamMasuk) {
                try {
                    $waktuMasuk = \Carbon\Carbon::createFromFormat('H:i', $jamMasuk, 'Asia/Jakarta');
                    $batasMasuk = $waktuMasuk->copy()->addMinutes($tolMasuk);

                    if ($now->lessThan($waktuMasuk)) {
                        $statusMasuk = 'absen_masuk';
                        $keteranganMasuk = '';
                    } elseif ($now->between($waktuMasuk, $batasMasuk)) {
                        $statusMasuk = 'absen_masuk';
                        $keteranganMasuk = '';
                    } elseif ($now->greaterThan($batasMasuk) && $now->lessThan($jam12)) {
                        $statusMasuk = 'terlambat';
                        $keteranganMasuk = 'Terlambat absen masuk';
                    }
                } catch (\Exception $e) {
                    return response()->json(['message' => 'Format jam masuk tidak valid'], 400);
                }
            }


            if (!$statusMasuk) {
                $statusMasuk = 'tidak_absen_masuk';
                $keteranganMasuk = 'Tidak absen masuk';
            }
        }

        $statusPresensi = $jadwalDinas ? 'dinas' : null;
        $keteranganDinas = null;

        if ($jadwalDinas) {
            $statusPresensi = 'dinas';
            $keteranganDinas = $jadwalDinas->keterangan;
        }
        // elseif (in_array($statusMasuk, ['absen_masuk', 'terlambat'])) {
        //     $statusPresensi = 'hadir';
        // }

        // Simpan presensi masuk normal
        $presensi = Presensi::create([
            'no_ktp' => $pegawai->no_ktp,
            'shift_id' => $shiftDetail->shift_id,
            'shift_detail_id' => $shiftDetail->id,
            'waktu_masuk' => $waktuMasukUntukSimpan,
            'status_masuk' => $statusMasuk,
            'lokasi_masuk' => $request->lokasi,
            'keterangan_masuk' => $keteranganDinas ? $keteranganDinas : $keteranganMasuk,
            'status_presensi' => $statusPresensi,
        ]);

        if ($pegawai->pegawai->profesi == 'driver') {
            $driverStatusPresensi = $jadwalDinas ? 'dinas' : 'hadir';
            $driverKeterangan = $jadwalDinas ? $jadwalDinas->keterangan : '';

            $presensi->update([
                'waktu_pulang' => $now,
                'status_pulang' => 'absen_pulang',
                'lokasi_pulang' => $request->lokasi,
                'keterangan_pulang' => $driverKeterangan,
                'status_presensi' => $driverStatusPresensi,
            ]);
        }

        if ($jadwalDinas) {
            $statusPresensiFinal = $jadwalDinas ? 'dinas' : 'hadir';
            $keteranganFinal = $jadwalDinas ? $jadwalDinas->keterangan : 'dinas';

            $presensi->update([
                'waktu_pulang' => $now,
                'status_pulang' => 'absen_pulang',
                'lokasi_pulang' => $request->lokasi,
                'keterangan_pulang' => $keteranganFinal,
                'status_presensi' => $statusPresensiFinal,
            ]);
        }



        return response()->json([
            'no_ktp' => $presensi->no_ktp,
            'shift_detail_id' => $presensi->shift_detail_id,
            'tanggal' => $presensi->waktu_masuk->format('Y-m-d'),
            'waktu' => $presensi->waktu_masuk->format('H:i:s'),
            'status' => $presensi->status_masuk,
            'keterangan' => $presensi->keterangan_masuk,
        ]);
    }


    private function handlePresensiPulang(Request $request, $presensi, $now, $shiftDetail, $jamPulang, $tolPulang, $jadwalDinas = null)
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

        $overtime = false;
        if ($now->gt($waktuPulang) && $now->diffInMinutes($waktuPulang) > 60) {
            $overtime = true;
        }


        // Tentukan status presensi berdasarkan jadwal dinas
        $statusPresensi = $this->calculateFinalStatus($presensi->status_masuk, $statusPulang, $jadwalDinas);
        $keteranganPulangFinal = $keteranganPulang;

        if ($jadwalDinas) {
            $statusPresensi = 'dinas';
            $keteranganPulangFinal = $jadwalDinas->keterangan;
        }

        // Update presensi dengan data pulang
        $presensi->update([
            'waktu_pulang' => $now,
            'status_pulang' => $statusPulang,
            'lokasi_pulang' => $request->lokasi,
            'keterangan_pulang' => $keteranganPulangFinal,
            'status_presensi' => $statusPresensi,
            'overtime' => $overtime,
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

    private function calculateFinalStatus($statusMasuk, $statusPulang, $jadwalDinas = null)
    {
        // Override jika ada dinas/izin/sakit/cuti
        if ($jadwalDinas) {
            return 'dinas';
        }
        $specialOverrides = ['izin', 'sakit', 'cuti'];
        if (in_array($statusMasuk, $specialOverrides)) {
            return $statusMasuk;
        }

        // Jika status masuk & pulang valid sempurna
        if ($statusMasuk === 'absen_masuk' && $statusPulang === 'absen_pulang') {
            return 'hadir';
        }

        // Jika masuk absen_masuk tapi pulang beda
        if ($statusMasuk === 'absen_masuk') {
            if ($statusPulang === 'pulang_awal') {
                return 'pulang_awal';
            }
            if ($statusPulang === 'tidak_absen_pulang' || $statusPulang === null) {
                return 'tidak_absen_pulang';
            }
        }

        // Jika masuk terlambat tapi pulang absah
        if ($statusMasuk === 'terlambat' && $statusPulang === 'absen_pulang') {
            return 'terlambat';
        }

        // Kalau semuanya gagal, fallback ke tidak_hadir
        return 'tidak_hadir';
    }


    // Presensi hari ini (masuk & keluar)
    public function today(Request $request)
    {
        $pegawai = $request->get('pegawai');

        $pegawai->load([
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit',
            'pegawai'
        ]);
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

        $pegawai->load([
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit',
            'pegawai'
        ]);
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
            $q->where('presensi_ms_unit_detail_id', $unitId);
        })->get();

        $result = [];
        foreach ($pegawais as $pegawai) {
            $query = Presensi::where('no_ktp', $pegawai->orang->no_ktp);
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
                'no_ktp' => $pegawai->orang->no_ktp,
                'nama' => $pegawai->orang->nama,
                'total_hadir' => $total_hadir,
                'total_tidak_masuk' => $total_tidak_masuk,
                'total_izin' => $total_izin,
                'total_cuti' => $total_cuti,
                'total_sakit' => $total_sakit,
            ];
        }
        return response()->json($result);
    }

    /**
     * Grafik rekap bulanan (1 tahun berjalan) untuk pegawai login
     * Mengembalikan agregat per bulan menggunakan variabel:
     * hadir, izin, sakit, cuti, tidak_hadir, dinas, lembur, terlambat,
     * pulang_awal, tidak_absen_masuk, tidak_absen_pulang, belum_presensi
     */

    //REKAP TAHUNAN PEGAWAI STATUS DIPISAH
    // public function rekapHistoryTahunanPegawai(Request $request)
    // {
    //     $pegawai = $request->get('pegawai');
    //     if (!$pegawai) {
    //         return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
    //     }

    //     $pegawai->load(['pegawai.unitDetailPresensi.unit', 'pegawai']);

    //     $tahun = (int) $request->query('tahun', now('Asia/Jakarta')->year);

    //     $result = [];

    //     for ($bulan = 1; $bulan <= 12; $bulan++) {
    //         $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
    //         $end = $start->copy()->endOfMonth();

    //         // Ambil presensi pada bulan tsb (format baru: 1 row per hari)
    //         $presensis = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
    //             ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
    //             ->get();

    //         // Ambil pengajuan pada bulan tsb
    //         $izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
    //             ->where('status', 'diterima')
    //             ->where(function ($q) use ($start, $end) {
    //                 $q->whereBetween('tanggal_mulai', [$start, $end])
    //                     ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //             })->get();

    //         $cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
    //             ->where('status', 'diterima')
    //             ->where(function ($q) use ($start, $end) {
    //                 $q->whereBetween('tanggal_mulai', [$start, $end])
    //                     ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //             })->get();

    //         $sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
    //             ->where('status', 'diterima')
    //             ->where(function ($q) use ($start, $end) {
    //                 $q->whereBetween('tanggal_mulai', [$start, $end])
    //                     ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //             })->get();

    //         $agg = [
    //             'hadir' => 0,
    //             'izin' => 0,
    //             'sakit' => 0,
    //             'cuti' => 0,
    //             'tidak_hadir' => 0,
    //             'dinas' => 0,
    //             'lembur' => 0,
    //             'terlambat' => 0,
    //             'pulang_awal' => 0,
    //             'tidak_absen_masuk' => 0,
    //             'tidak_absen_pulang' => 0,
    //             'belum_presensi' => 0,
    //         ];

    //         // Loop setiap hari kerja efektif dalam bulan
    //         for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
    //             $carbon = $date->copy();

    //             // Skip weekend
    //             if ($carbon->isSaturday() || $carbon->isSunday()) {
    //                 continue;
    //             }

    //             // Skip hari libur unit
    //             $unit = $pegawai->pegawai->unitDetailPresensi->unit ?? null;
    //             if ($unit) {
    //                 $isHariLibur = \App\Models\HariLibur::isHariLibur($unit->id, $carbon->toDateString());
    //                 if ($isHariLibur) {
    //                     continue;
    //                 }
    //             }

    //             $tanggal = $carbon->format('Y-m-d');

    //             // Filter presensi hari ini
    //             $presensiHari = $presensis->filter(function ($p) use ($tanggal) {
    //                 return $p->waktu_masuk && \Carbon\Carbon::parse($p->waktu_masuk)->format('Y-m-d') === $tanggal;
    //             });

    //             // Metrik tambahan (dihitung bila ada presensi)
    //             if ($presensiHari->count()) {
    //                 if ($presensiHari->where('status_presensi', 'dinas')->count()) {
    //                     $agg['dinas']++;
    //                 }
    //                 if ($presensiHari->where('overtime', true)->count()) {
    //                     $agg['lembur']++;
    //                 }
    //                 if ($presensiHari->where('status_masuk', 'terlambat')->count()) {
    //                     $agg['terlambat']++;
    //                 }
    //                 if ($presensiHari->where('status_pulang', 'pulang_awal')->count()) {
    //                     $agg['pulang_awal']++;
    //                 }
    //                 if ($presensiHari->where('status_masuk', 'tidak_absen_masuk')->count()) {
    //                     $agg['tidak_absen_masuk']++;
    //                 }
    //                 if ($presensiHari->where('status_pulang', 'tidak_absen_pulang')->count()) {
    //                     $agg['tidak_absen_pulang']++;
    //                 }
    //             }

    //             // Status utama
    //             $status = null;
    //             if ($presensiHari->count()) {
    //                 if ($presensiHari->whereIn('status_presensi', ['hadir', 'dinas'])->count()) {
    //                     $status = 'hadir';
    //                 } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
    //                     $status = 'tidak_hadir';
    //                 }
    //             }
    //             if (!$status || $status === 'tidak_hadir') {
    //                 foreach ($izin as $i) {
    //                     if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
    //                         $status = 'izin';
    //                         break;
    //                     }
    //                 }
    //             }
    //             if (!$status || $status === 'tidak_hadir') {
    //                 foreach ($cuti as $c) {
    //                     if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
    //                         $status = 'cuti';
    //                         break;
    //                     }
    //                 }
    //             }
    //             if (!$status || $status === 'tidak_hadir') {
    //                 foreach ($sakit as $s) {
    //                     if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
    //                         $status = 'sakit';
    //                         break;
    //                     }
    //                 }
    //             }
    //             if (!$status) {
    //                 $status = 'belum_presensi';
    //             }
    //             if (isset($agg[$status])) {
    //                 $agg[$status]++;
    //             }
    //         }

    //         $result[] = array_merge([
    //             'bulan' => $bulan,
    //             'tahun' => $tahun,
    //         ], $agg);
    //     }

    //     return response()->json($result);
    // }

    public function rekapHistoryTahunanPegawai(Request $request)
    {
        $pegawai = $request->get('pegawai');
        if (!$pegawai) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
        }

        $pegawai->load(['pegawai.unitDetailPresensi.unit', 'pegawai']);
        $tahun = (int) $request->query('tahun', now('Asia/Jakarta')->year);

        $result = [];

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = $start->copy()->endOfMonth();

            // Ambil presensi berdasarkan waktu_masuk
            $presensis = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
                ->orderBy('waktu_masuk')
                ->get();

            // Ambil pengajuan
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

            $agg = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'cuti' => 0,
                'tidak_hadir' => 0,
                'dinas' => 0,
                'lembur' => 0,
                'terlambat' => 0,
                'pulang_awal' => 0,
                'tidak_absen_masuk' => 0,
                'tidak_absen_pulang' => 0,
                'belum_presensi' => 0,
            ];


            $hariEfektif = 0;
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $carbon = $date->copy();

                // Skip weekend
                if ($carbon->isSaturday() || $carbon->isSunday()) continue;

                // Skip libur unit
                $unit = $pegawai->pegawai->unitDetailPresensi->unit ?? null;
                if ($unit) {
                    $isHariLibur = \App\Models\HariLibur::isHariLibur($unit->id, $carbon->toDateString());
                    if ($isHariLibur) continue;
                }
                $hariEfektif++;

                $tanggal = $carbon->format('Y-m-d');

                // Presensi hari ini (bisa >1)
                $presensiHari = $presensis->filter(function ($p) use ($tanggal) {
                    return $p->waktu_masuk && \Carbon\Carbon::parse($p->waktu_masuk)->format('Y-m-d') === $tanggal;
                });

                $status = null;

                // PRIORITAS sama seperti bulanan
                if ($presensiHari->count() && $presensiHari->where('status_presensi', 'dinas')->count()) {
                    $status = 'dinas';
                } elseif ($presensiHari->count() && $presensiHari->where('overtime', true)->count()) {
                    $status = 'lembur';
                } elseif ($presensiHari->count() && $presensiHari->where('status_masuk', 'terlambat')->count()) {
                    $status = 'terlambat';
                } elseif ($presensiHari->count() && $presensiHari->where('status_masuk', 'tidak_absen_masuk')->count()) {
                    $status = 'tidak_absen_masuk';
                } elseif ($presensiHari->count() && $presensiHari->where('status_pulang', 'pulang_awal')->count()) {
                    $status = 'pulang_awal';
                } elseif (
                    $presensiHari->count() &&
                    (
                        $presensiHari->where('status_pulang', 'tidak_absen_pulang')->count()
                        || $presensiHari->whereNull('status_pulang')->count()
                    )
                ) {
                    $status = 'tidak_absen_pulang';
                } else {
                    if (
                        $presensiHari->count()
                        && $presensiHari->where('status_masuk', 'absen_masuk')->count()
                        && $presensiHari->where('status_pulang', 'absen_pulang')->count()
                    ) {
                        $status = 'hadir';
                    }
                }

                // Cek izin/cuti/sakit bila belum ada status
                if (!$status) {
                    foreach ($izin as $i) {
                        if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                            $status = 'izin';
                            break;
                        }
                    }
                }
                if (!$status) {
                    foreach ($cuti as $c) {
                        if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                            $status = 'cuti';
                            break;
                        }
                    }
                }
                if (!$status) {
                    foreach ($sakit as $s) {
                        if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                            $status = 'sakit';
                            break;
                        }
                    }
                }

                // Kalau tetap null -> bedakan tidak_hadir vs belum_presensi
                if (!$status) {
                    if ($carbon->lte(now('Asia/Jakarta')->startOfDay())) {
                        $status = 'tidak_hadir';
                    } else {
                        $status = 'belum_presensi';
                    }
                }

                if (isset($agg[$status])) {
                    $agg[$status]++;
                }
            }

            $result[] = array_merge([
                'bulan' => $bulan,
                'tahun' => $tahun,
                'hari_efektif' => $hariEfektif
            ], $agg);
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

        $tanggal = $request->query('tanggal', Carbon::today()->toDateString());
        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('presensi_ms_unit_detail_id', $unitId);
        })
            ->with('orang:id,no_ktp,nama') // ambil no_ktp & nama dari ms_orang
            ->get(['id', 'id_orang']); // ambil id + fk saja

        $no_ktps = $pegawais->pluck('orang.no_ktp');

        $pegawaiMap = $pegawais->mapWithKeys(function ($pegawai) {
            return [$pegawai->orang->no_ktp => $pegawai->orang];
        });

        // Menggunakan format baru - 1 row per hari
        $query = Presensi::whereIn('no_ktp', $no_ktps);

        if ($tanggal) {
            $query->whereDate('waktu_masuk', $tanggal);
        }

        $presensis = $query->orderBy('waktu_masuk', 'desc')->get();

        $result = $presensis->map(function ($p) use ($pegawaiMap) {
            $pegawai = $pegawaiMap[$p->no_ktp] ?? null;
            return [
                'id'               => $p->id,
                'no_ktp'           => $p->no_ktp,
                'nama'             => $pegawai?->nama,
                'status_masuk'     => $p->status_masuk,
                'status_pulang'    => $p->status_pulang,
                'status_presensi'  => $p->status_presensi,
                'waktu_masuk'      => $p->waktu_masuk,
                'waktu_pulang'     => $p->waktu_pulang,
                'keterangan_masuk' => $p->keterangan_masuk,
                'keterangan_pulang' => $p->keterangan_pulang,
                'created_at'       => $p->created_at,
                'updated_at'       => $p->updated_at,
            ];
        });

        return response()->json($result);
    }

    /**
     * Rekap history presensi pegawai per bulan (pegawai yang login)
     */
    // public function rekapHistoryBulananPegawai(Request $request)
    // {
    //     $pegawai = $request->get('pegawai');
    //     $pegawai->load([
    //         'pegawai.shiftDetail.shift',
    //         'pegawai.unitDetailPresensi.unit',
    //         'pegawai'
    //     ]);
    //     if (!$pegawai) {
    //         return response()->json(['message' => 'Pegawai tidak ditemukan'], 401);
    //     }

    //     $bulan = $request->query('bulan', now('Asia/Jakarta')->month);
    //     $tahun = $request->query('tahun', now('Asia/Jakarta')->year);

    //     // Ambil semua tanggal di bulan tsb
    //     $start = \Carbon\Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
    //     $end = $start->copy()->endOfMonth();

    //     $tanggalList = [];
    //     for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
    //         $tanggalList[] = $date->format('Y-m-d');
    //     }
    //     $hariEfektif = 0;
    //     foreach ($tanggalList as $tanggal) {
    //         $carbon = \Carbon\Carbon::parse($tanggal);

    //         if ($carbon->isSaturday() || $carbon->isSunday()) {
    //             continue;
    //         }

    //         $isHariLibur = \App\Models\HariLibur::isHariLibur($pegawai->unitDetailPresensi->unit->id, $carbon->toDateString());
    //         if ($isHariLibur) {
    //             continue;
    //         }

    //         $hariEfektif++;
    //     }

    //     // Ambil presensi pegawai di bulan tsb
    //     $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
    //         ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
    //         ->orderBy('waktu_masuk')
    //         ->get();

    //     // Ambil pengajuan izin, cuti, sakit
    //     $izin = \App\Models\PengajuanIzin::where('pegawai_id', $pegawai->id)
    //         ->where('status', 'diterima')
    //         ->where(function ($q) use ($start, $end) {
    //             $q->whereBetween('tanggal_mulai', [$start, $end])
    //                 ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //         })->get();

    //     $cuti = \App\Models\PengajuanCuti::where('pegawai_id', $pegawai->id)
    //         ->where('status', 'diterima')
    //         ->where(function ($q) use ($start, $end) {
    //             $q->whereBetween('tanggal_mulai', [$start, $end])
    //                 ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //         })->get();

    //     $sakit = \App\Models\PengajuanSakit::where('pegawai_id', $pegawai->id)
    //         ->where('status', 'diterima')
    //         ->where(function ($q) use ($start, $end) {
    //             $q->whereBetween('tanggal_mulai', [$start, $end])
    //                 ->orWhereBetween('tanggal_selesai', [$start, $end]);
    //         })->get();

    //     // Inisialisasi result
    //     $result = [
    //         'hadir' => 0,
    //         'izin' => 0,
    //         'sakit' => 0,
    //         'cuti' => 0,
    //         'tidak_hadir' => 0,
    //         'dinas' => 0,
    //         'lembur' => 0,
    //         'terlambat' => 0,
    //         'pulang_awal' => 0,
    //         'tidak_absen_masuk' => 0,
    //         'tidak_absen_pulang' => 0,
    //         'belum_presensi' => 0,
    //         'tanggal_hadir' => [],
    //         'tanggal_izin' => [],
    //         'tanggal_sakit' => [],
    //         'tanggal_cuti' => [],
    //         'tanggal_tidak_hadir' => [],
    //         'tanggal_dinas' => [],
    //         'tanggal_lembur' => [],
    //         'tanggal_terlambat' => [],
    //         'tanggal_pulang_awal' => [],
    //         'tanggal_tidak_absen_masuk' => [],
    //         'tanggal_tidak_absen_pulang' => [],
    //         'tanggal_belum_presensi' => [],
    //         'bulan' => (string)$bulan,
    //         'tahun' => (string)$tahun,
    //         'hari_efektif' => $hariEfektif
    //     ];

    //     foreach ($tanggalList as $tanggal) {
    //         $carbon = \Carbon\Carbon::parse($tanggal);

    //         // Skip kalau weekend atau libur, jadi tidak dihitung "belum presensi"
    //         if ($carbon->isSaturday() || $carbon->isSunday()) {
    //             continue;
    //         }

    //         $isHariLibur = \App\Models\HariLibur::isHariLibur($pegawai->unitDetailPresensi->unit->id, $carbon->toDateString());
    //         if ($isHariLibur) {
    //             continue;
    //         }

    //         $status = null;

    //         // Cek presensi (safe null check)
    //         $presensiHari = $presensi->filter(function ($p) use ($tanggal) {
    //             return $p->waktu_masuk && \Carbon\Carbon::parse($p->waktu_masuk)->format('Y-m-d') === $tanggal;
    //         });

    //         if ($presensiHari->count()) {
    //             $dayString = $carbon->format('d');

    //             if ($presensiHari->where('status_presensi', 'dinas')->count()) {
    //                 $result['dinas']++;
    //                 $result['tanggal_dinas'][] = $dayString;
    //             }

    //             if ($presensiHari->where('overtime', true)->count()) {
    //                 $result['overtime']++;
    //                 $result['tanggal_overtime'][] = $dayString;
    //             }

    //             if ($presensiHari->where('status_masuk', 'terlambat')->count()) {
    //                 $result['terlambat']++;
    //                 $result['tanggal_terlambat'][] = $dayString;
    //             }

    //             if ($presensiHari->where('status_pulang', 'pulang_awal')->count()) {
    //                 $result['pulang_awal']++;
    //                 $result['tanggal_pulang_awal'][] = $dayString;
    //             }

    //             if ($presensiHari->where('status_masuk', 'tidak_absen_masuk')->count()) {
    //                 $result['tidak_absen_masuk']++;
    //                 $result['tanggal_tidak_absen_masuk'][] = $dayString;
    //             }

    //             if ($presensiHari->where('status_pulang', 'tidak_absen_pulang')->count()) {
    //                 $result['tidak_absen_pulang']++;
    //                 $result['tanggal_tidak_absen_pulang'][] = $dayString;
    //             }
    //         }

    //         if ($presensiHari->count()) {
    //             if ($presensiHari->whereIn('status_presensi', ['hadir', 'dinas'])->count()) {
    //                 $status = 'hadir';
    //             } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
    //                 $status = 'tidak_hadir';
    //             }
    //         }

    //         // Cek izin
    //         if (!$status || $status === 'tidak_hadir') {
    //             foreach ($izin as $i) {
    //                 if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
    //                     $status = 'izin';
    //                     break;
    //                 }
    //             }
    //         }

    //         // Cek cuti
    //         if (!$status || $status === 'tidak_hadir') {
    //             foreach ($cuti as $c) {
    //                 if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
    //                     $status = 'cuti';
    //                     break;
    //                 }
    //             }
    //         }

    //         // Cek sakit
    //         if (!$status || $status === 'tidak_hadir') {
    //             foreach ($sakit as $s) {
    //                 if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
    //                     $status = 'sakit';
    //                     break;
    //                 }
    //             }
    //         }

    //         if (!$status) {
    //             $status = 'belum_presensi';
    //         }

    //         // Hitung jumlah & simpan tanggal
    //         if (isset($result[$status])) {
    //             $result[$status]++;
    //         }
    //         $result['tanggal_' . $status][] = $carbon->format('d');
    //     }


    //     return response()->json($result);
    // }

    // REKAP BULANAN STATUS TERPISAH
    public function rekapHistoryBulananPegawai(Request $request)
    {
        $pegawai = $request->get('pegawai');
        $pegawai->load([
            'pegawai.shiftDetail.shift',
            'pegawai.unitDetailPresensi.unit',
            'pegawai'
        ]);
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

        // Hitung hari efektif
        $hariEfektif = 0;
        foreach ($tanggalList as $tanggal) {
            $carbon = \Carbon\Carbon::parse($tanggal);
            if ($carbon->isSaturday() || $carbon->isSunday()) continue;
            if (\App\Models\HariLibur::isHariLibur($pegawai->unitDetailPresensi->unit->id, $carbon->toDateString())) continue;
            $hariEfektif++;
        }

        // Ambil presensi pegawai di bulan tsb (pakai waktu_masuk)
        $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereBetween('waktu_masuk', [$start->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])
            ->orderBy('waktu_masuk')
            ->get();

        // Ambil pengajuan izin, cuti, sakit
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

        // Inisialisasi result (tetap sama struktur)
        $result = [
            'hadir' => 0,
            'izin' => 0,
            'sakit' => 0,
            'cuti' => 0,
            'tidak_hadir' => 0,
            'dinas' => 0,
            'lembur' => 0,
            'terlambat' => 0,
            'pulang_awal' => 0,
            'tidak_absen_masuk' => 0,
            'tidak_absen_pulang' => 0,
            'belum_presensi' => 0,
            'tanggal_hadir' => [],
            'tanggal_izin' => [],
            'tanggal_sakit' => [],
            'tanggal_cuti' => [],
            'tanggal_tidak_hadir' => [],
            'tanggal_dinas' => [],
            'tanggal_lembur' => [],
            'tanggal_terlambat' => [],
            'tanggal_pulang_awal' => [],
            'tanggal_tidak_absen_masuk' => [],
            'tanggal_tidak_absen_pulang' => [],
            'tanggal_belum_presensi' => [],
            'bulan' => (string)$bulan,
            'tahun' => (string)$tahun,
            'hari_efektif' => $hariEfektif
        ];

        foreach ($tanggalList as $tanggal) {
            $carbon = \Carbon\Carbon::parse($tanggal);

            // Skip weekend/libur
            if ($carbon->isSaturday() || $carbon->isSunday()) continue;
            if (\App\Models\HariLibur::isHariLibur($pegawai->unitDetailPresensi->unit->id, $carbon->toDateString())) continue;

            $status = null;
            $dayString = $carbon->format('d');

            // Kumpulkan presensi hari itu (bisa >1 row)
            $presensiHari = $presensi->filter(function ($p) use ($tanggal) {
                return $p->waktu_masuk && \Carbon\Carbon::parse($p->waktu_masuk)->format('Y-m-d') === $tanggal;
            });

            // PRIORITAS:
            // 1) dinas
            // 1) dinas
            if ($presensiHari->where('status_presensi', 'dinas')->count()) {
                $status = 'dinas';
                $result['tanggal_dinas'][] = $dayString;
            }
            // 2) lembur
            elseif ($presensiHari->where('overtime', true)->count()) {
                $status = 'lembur';
                $result['tanggal_lembur'][] = $dayString;
            }
            // 3) kalau ada absen_masuk
            elseif ($presensiHari->where('status_masuk', 'absen_masuk')->count()) {
                if ($presensiHari->where('status_pulang', 'pulang_awal')->count()) {
                    $status = 'pulang_awal';
                    $result['tanggal_pulang_awal'][] = $dayString;
                } elseif ($presensiHari->where('status_pulang', 'tidak_absen_pulang')->count()) {
                    $status = 'tidak_absen_pulang';
                    $result['tanggal_tidak_absen_pulang'][] = $dayString;
                } elseif ($presensiHari->where('status_pulang', 'absen_pulang')->count()) {
                    $status = 'hadir';
                    $result['tanggal_hadir'][] = $dayString;
                } else {
                    // status_pulang = null → dianggap tidak_absen_pulang
                    $status = 'tidak_absen_pulang';
                    $result['tanggal_tidak_absen_pulang'][] = $dayString;
                }
            }

            // 4) kalau status_masuk = terlambat
            elseif ($presensiHari->where('status_masuk', 'terlambat')->count()) {
                $status = 'terlambat';
                $result['tanggal_terlambat'][] = $dayString;
            }
            // 5) kalau status_masuk = tidak_absen_masuk
            elseif ($presensiHari->where('status_masuk', 'tidak_absen_masuk')->count()) {
                $status = 'tidak_absen_masuk';
                $result['tanggal_tidak_absen_masuk'][] = $dayString;
            }


            // Kalau tidak ada status dari presensi -> cek izin/cuti/sakit
            if (!$status) {
                foreach ($izin as $i) {
                    if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                        $status = 'izin';
                        $result['tanggal_izin'][] = $dayString;
                        break;
                    }
                }
            }
            if (!$status) {
                foreach ($cuti as $c) {
                    if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                        $status = 'cuti';
                        $result['tanggal_cuti'][] = $dayString;
                        break;
                    }
                }
            }
            if (!$status) {
                foreach ($sakit as $s) {
                    if ($tanggal >= $s->tanggal_mulai && $tanggal <= $s->tanggal_selesai) {
                        $status = 'sakit';
                        $result['tanggal_sakit'][] = $dayString;
                        break;
                    }
                }
            }

            // Kalau tetap null → bedakan tidak_hadir vs belum_presensi
            if (!$status) {
                if ($carbon->lte(now('Asia/Jakarta')->startOfDay())) {
                    $status = 'tidak_hadir';
                    $result['tanggal_tidak_hadir'][] = $dayString;
                } else {
                    $status = 'belum_presensi';
                    $result['tanggal_belum_presensi'][] = $dayString;
                }
            }

            // Tambah hitungan status utama (satu status per hari)
            if (isset($result[$status])) {
                $result[$status]++;
            }
        }

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
            $q->where('presensi_ms_unit_detail_id', $unitId);
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
            $presensiQuery = \App\Models\Presensi::where('no_ktp', $pegawai->orang->no_ktp);
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
            if ($pegawai->presensi_ms_unit_detail_id) {
                $unitDetail = \App\Models\UnitDetail::find($pegawai->presensi_ms_unit_detail_id);
                $unitDetailName = $unitDetail?->nama;
            }


            $result[] = [
                'pegawai' => [
                    'id' => $pegawai->id,
                    'no_ktp' => $pegawai->orang->no_ktp,
                    'nama' => $pegawai->orang->nama,
                    'unit_detail_name' => $pegawai->unit ? $pegawai->unit->nama : null,
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
        $pegawai = MsPegawai::with('orang')->where('id', $pegawai_id)->firstOrFail();

        // Get unit_id using helper
        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        // // Validasi pegawai milik unit admin
        // if (!$pegawai->unitDetailPresensi || $pegawai->unitDetailPresensi->unit_id != $unitId) {
        //     return response()->json(['message' => 'Tidak memiliki akses edit presensi pegawai ini'], 403);
        // }

        // Menggunakan format baru - 1 row per hari
        $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->orang->no_ktp)
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
        $pegawai = \App\Models\MsPegawai::with('orang')->where('id_orang', $pegawai_id)->first();
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
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->orang->no_ktp)
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
                    if ($presensiHari->whereIn('status_presensi', ['hadir', 'dinas'])->count()) {
                        $status = 'hadir';
                    } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                        $status = 'tidak_hadir';
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
                    foreach ($izin as $i) {
                        if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                            $status = 'izin';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
                    foreach ($cuti as $c) {
                        if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                            $status = 'cuti';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
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
            $q->where('presensi_ms_unit_detail_id', $unitId);
        })
            ->with(['unitDetailPresensi', 'orang'])
            ->get();

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
                $isLibur = isset($hariLiburMap[$pegawai->presensi_ms_unit_detail_id][$date->format('Y-m-d')]);
                if ($isLibur) $jumlahLibur++;
                if (!$isWeekend && !$isLibur) $hariEfektif++;
            }
            // Ambil presensi pegawai di bulan tsb (format baru)
            $presensi = \App\Models\Presensi::where('no_ktp', $pegawai->orang->no_ktp)
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
                    if ($presensiHari->where('status_presensi', 'dinas')->count()) {
                        $status = 'dinas';
                    } elseif ($presensiHari->where('status_presensi', 'hadir')->count()) {
                        $status = 'hadir';
                    } elseif ($presensiHari->where('status_presensi', 'tidak_hadir')->count()) {
                        $status = 'tidak_hadir';
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
                    foreach ($izin as $i) {
                        if ($tanggal >= $i->tanggal_mulai && $tanggal <= $i->tanggal_selesai) {
                            $status = 'izin';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
                    foreach ($cuti as $c) {
                        if ($tanggal >= $c->tanggal_mulai && $tanggal <= $c->tanggal_selesai) {
                            $status = 'cuti';
                            break;
                        }
                    }
                }
                if (!$status || $status === 'tidak_hadir') {
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
            $counts = collect($rekap)->countBy();
            $jumlahHadir = $counts->get('hadir', 0);
            $jumlahIzin = $counts->get('izin', 0);
            $jumlahSakit = $counts->get('sakit', 0);
            $jumlahCuti = $counts->get('cuti', 0);
            $jumlahTidakHadir = $counts->get('tidak_hadir', 0);
            $jumlahDinas = $counts->get('dinas', 0);
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

            $nominalPerhari = $hariEfektif > 0 ? $nominalLaukPauk / $hariEfektif : 0;

            $potonganDinas = $jumlahDinas * $nominalPerhari;

            // Hitung nominal lauk pauk setelah potongan (hanya untuk perhitungan internal)
            $nominalLaukPaukSetelahPotongan = max(0, $nominalLaukPauk - $totalPotongan - $potonganDinas);

            $result[] = [
                'no' => $no++,
                'nik' => $pegawai->orang->no_ktp,
                'nama_pegawai' => $pegawai->orang->nama,
                'unit_kerja' => $pegawai->unit ? $pegawai->unit->nama : null,
                'hari_efektif' => $hariEfektif,
                'jumlah_hadir' => $jumlahHadir,
                'jumlah_izin' => $jumlahIzin,
                'jumlah_sakit' => $jumlahSakit,
                'jumlah_cuti' => $jumlahCuti,
                'jumlah_tidak_masuk' => $jumlahTidakHadir,
                'jumlah_dinas' => $jumlahDinas,
                'jumlah_terlambat' => $jumlahTerlambat,
                'jumlah_pulang_awal' => $jumlahPulangAwal,
                'jumlah_jam_datang_kosong' => $jumlahJamDatangKosong,
                'jumlah_jam_pulang_kosong' => $jumlahJamPulangKosong,
                'lembur' => 0,
                'jumlah_libur' => $jumlahLibur,
                'nominal_lauk_pauk' => $nominalLaukPaukSetelahPotongan
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
        $pegawai = MsPegawai::with('orang')->where('id', $pegawai_id)->firstOrFail();
        $noKtp   = $pegawai->orang->no_ktp;

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
            $shiftDetail = ShiftDetail::find($pegawai->presensi_shift_detail_id);

            // Tentukan nama hari (lowercase) untuk mapping kolom shift
            $hari = strtolower(Carbon::parse($tanggalPresensi)->locale('id')->isoFormat('dddd'));
            // Sesuaikan format kolom (misal: "senin_masuk", "senin_pulang")
            $kolomMasuk = "{$hari}_masuk";
            $kolomPulang = "{$hari}_pulang";

            // Jam kerja sesuai shift + tanggal presensi
            $shiftMasuk = $shiftDetail->$kolomMasuk;
            $shiftPulang = $shiftDetail->$kolomPulang;

            // Kalau libur atau null → skip hari ini
            if (!$shiftMasuk || !$shiftPulang || strtolower($shiftMasuk) === 'libur' || strtolower($shiftPulang) === 'libur') {
                continue; // lompat ke iterasi berikutnya
            }

            $jamKerjaMasuk = Carbon::parse($tanggalPresensi . ' ' . $shiftMasuk);
            $jamKerjaPulang = Carbon::parse($tanggalPresensi . ' ' . $shiftPulang);


            // Jam presensi masuk/pulang
            $jamMasuk = $p->waktu_masuk ? Carbon::parse($p->waktu_masuk) : null;
            $jamKeluar = $p->waktu_pulang ? Carbon::parse($p->waktu_pulang) : null;

            // Hitung datang cepat / telat
            $menitCepat = $menitTelat = 0;
            if ($jamMasuk) {
                if ($jamMasuk->lessThan($jamKerjaMasuk)) {
                    $menitCepat = $jamMasuk->diffInMinutes($jamKerjaMasuk);
                } elseif ($jamMasuk->greaterThan($jamKerjaMasuk)) {
                    $menitTelat = $jamKerjaMasuk->diffInMinutes($jamMasuk);
                }
            }

            // Hitung pulang cepat / lembur
            $menitPulangCepat = $menitLembur = 0;
            if ($jamKeluar) {
                // Selisih menit: jamKeluar - jamKerjaPulang
                if ($jamKeluar->lessThan($jamKerjaPulang)) {
                    $menitPulangCepat = $jamKeluar->diffInMinutes($jamKerjaPulang);
                } elseif ($jamKeluar->greaterThan($jamKerjaPulang)) {
                    $menitLembur = $jamKerjaPulang->diffInMinutes($jamKeluar);
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
        $upk = DB::selectOne("
                SELECT 
                    CASE
                        WHEN oyg.id IS NOT NULL
                        AND oyg.aktif = 1 THEN oy.nama
                        ELSE upk.nama
                    END AS upk
                FROM ms_pegawai
                LEFT JOIN ms_unit upk ON ms_pegawai.id_upk = upk.id
                LEFT JOIN organ_yayasan_anggota oyg ON oyg.id_orang = ms_pegawai.id_orang
                LEFT JOIN organ_yayasan_jabatan oyj ON oyj.id = oyg.id_organ_jabatan
                LEFT JOIN organ_yayasan oy ON oy.id = oyj.id_organ
                WHERE ms_pegawai.id_orang = ?
                LIMIT 1
            ", [$pegawai->id_orang]);

        $upkName = $upk ? $upk->upk : null;

        return response()->json([
            'pegawai' => [
                'no_ktp' => $pegawai->orang->no_ktp,
                'nama' => $pegawai->orang->nama,
                'unit_kerja' => $pegawai->unit ? $pegawai->unit->nama : null,
                'jabatan' => $upkName,
            ],
            'periode' => [
                'bulan' => $bulan,
                'tahun' => $tahun,
            ],
            'data' => $data
        ]);
    }

    public function getOvertimePegawai(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $unitResult = AdminUnitHelper::getUnitId($request);
        if ($unitResult['error']) {
            return response()->json(['message' => $unitResult['error']], 400);
        }
        $unitId = $unitResult['unit_id'];

        $bulan = $request->query('bulan');
        $tahun = $request->query('tahun');

        $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($unitId) {
            $q->where('presensi_ms_unit_detail_id', $unitId);
        })
            ->whereHas('orang.presensi', function ($q) use ($bulan, $tahun) {
                if ($bulan) {
                    $q->whereMonth('waktu_pulang', $bulan);
                }
                if ($tahun) {
                    $q->whereYear('waktu_pulang', $tahun);
                }
                $q->where('overtime', 1);
            })
            ->with([
                'unitDetailPresensi.unit',
                'orang.presensi' => function ($q) use ($bulan, $tahun) {
                    if ($bulan) {
                        $q->whereMonth('waktu_pulang', $bulan);
                    }
                    if ($tahun) {
                        $q->whereYear('waktu_pulang', $tahun);
                    }
                    $q->where('overtime', 1)
                        ->with('shiftDetail')
                        ->orderBy('waktu_pulang', 'desc');
                }
            ])
            ->with('orang:id,no_ktp,nama')
            ->get();

        $result = collect();

        foreach ($pegawais as $pegawai) {
            foreach ($pegawai->orang->presensi as $p) {
                $waktuPulang = \Carbon\Carbon::parse($p->waktu_pulang);

                $mapHari = [
                    'monday' => 'senin',
                    'tuesday' => 'selasa',
                    'wednesday' => 'rabu',
                    'thursday' => 'kamis',
                    'friday' => 'jumat',
                    'saturday' => 'sabtu',
                    'sunday' => 'minggu'
                ];
                $hariKey = $mapHari[strtolower($waktuPulang->format('l'))] ?? null;

                $jamPulangShift = null;
                if ($p->shiftDetail && $hariKey && isset($p->shiftDetail->{$hariKey . '_pulang'})) {
                    $shiftTime = $p->shiftDetail->{$hariKey . '_pulang'}; // misal "17:00"
                    $jamPulangShift = $waktuPulang->copy()->setTimeFromTimeString($shiftTime);
                }

                $menitOvertime = 0;
                if ($jamPulangShift && $waktuPulang->gt($jamPulangShift)) {
                    $menitOvertime = $jamPulangShift->diffInMinutes($waktuPulang);
                }

                $result->push([
                    'no_ktp' => $pegawai->orang->no_ktp,
                    'nama' => $pegawai->orang->nama,
                    'unit_detail' => $pegawai->unitDetailPresensi->unit->nama ?? null,
                    'tanggal' => $waktuPulang->format('Y-m-d'),
                    'waktu_masuk' => $p->waktu_masuk ? \Carbon\Carbon::parse($p->waktu_masuk)->format('H:i') : null,
                    'waktu_pulang' => $waktuPulang->format('H:i'),
                    'menit_overtime' => $menitOvertime
                ]);
            }
        }


        return response()->json($result->values());
    }

    public function adminPresensiPegawai(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $request->validate([
            'tanggal' => 'required|date',
            'keterangan' => 'nullable|string|max:255',
            'pegawai_ids' => 'required|array',
            'pegawai_ids.*' => 'exists:ms_pegawai,id',
        ]);

        $pegawais = MsPegawai::with('shiftDetail', 'orang')
            ->whereIn('id', $request->pegawai_ids)
            ->get();

        if ($pegawais->isEmpty()) {
            return response()->json(['message' => 'Pegawai tidak ditemukan'], 404);
        }

        $createdPresensi = [];
        $errors = [];

        $start = Carbon::parse($request->tanggal_mulai);
        $end = Carbon::parse($request->tanggal_selesai);

        foreach ($pegawais as $pegawai) {
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $tanggal = $date->format('Y-m-d');

                // Cek duplikat presensi
                $existingPresensi = Presensi::where('no_ktp', $pegawai->orang->no_ktp ?? null)
                    ->whereDate('waktu_masuk', $tanggal)
                    ->first();

                if ($existingPresensi) {
                    $errors[] = "Pegawai {$pegawai->orang->nama} sudah memiliki presensi pada tanggal {$tanggal}";
                    continue;
                }

                $shiftDetail = $pegawai->shiftDetail;
                if (!$shiftDetail) {
                    $errors[] = "Pegawai {$pegawai->orang->nama} tidak memiliki shift detail";
                    continue;
                }

                $waktuMasuk = $this->getWaktuMasukShift($shiftDetail, $date);
                $waktuPulang = $this->getWaktuPulangShift($shiftDetail, $date);

                if (!$waktuMasuk || !$waktuPulang) {
                    $errors[] = "Pegawai {$pegawai->orang->nama} tidak memiliki jam kerja pada hari " . $date->locale('id')->isoFormat('dddd');
                    continue;
                }

                try {
                    $presensi = Presensi::create([
                        'no_ktp' => $pegawai->orang->no_ktp,
                        'shift_id' => $shiftDetail->shift_id,
                        'shift_detail_id' => $shiftDetail->id,
                        'waktu_masuk' => $waktuMasuk,
                        'waktu_pulang' => $waktuPulang,
                        'status_masuk' => 'absen_masuk',
                        'status_pulang' => 'absen_pulang',
                        'lokasi_masuk' => null,
                        'lokasi_pulang' => null,
                        'keterangan_masuk' => $request->keterangan,
                        'keterangan_pulang' => $request->keterangan,
                        'status_presensi' => 'hadir',
                    ]);

                    $createdPresensi[] = [
                        'pegawai' => $pegawai->orang->nama,
                        'tanggal' => $tanggal,
                        'presensi_id' => $presensi->id,
                    ];
                } catch (\Exception $e) {
                    $errors[] = "Gagal membuat presensi untuk pegawai {$pegawai->orang->nama} pada tanggal {$tanggal}: " . $e->getMessage();
                }
            }
        }

        return response()->json([
            'message' => 'Berhasil Mempresensikan pegawai',
            'created_count' => count($createdPresensi),
            'error_count' => count($errors),
            'created_data' => $createdPresensi,
            'errors' => $errors,
        ]);
    }

    /**
     * Get waktu masuk berdasarkan shift detail dan tanggal
     * 
     * @param ShiftDetail $shiftDetail
     * @param Carbon $date
     * @return Carbon|null
     */
    private function getWaktuMasukShift($shiftDetail, $date)
    {
        $hari = strtolower($date->locale('id')->isoFormat('dddd'));
        $masukKey = $hari . '_masuk';
        //dd($masukKey, $shiftDetail->$masukKey);
        $jamString = trim($shiftDetail->$masukKey ?? '');

        if (!$jamString) {
            return null; // tidak ada data jam
        }

        try {
            // Gunakan H:i karena di DB formatnya "08:00"
            $jamMasuk = Carbon::createFromFormat('H:i', $jamString);
            return $date->copy()->setTime($jamMasuk->hour, $jamMasuk->minute, 0);
        } catch (\Exception $e) {
            //Log::error("Format jam masuk tidak valid: {$jamString}");
            return null;
        }
    }


    /**
     * Get waktu pulang berdasarkan shift detail dan tanggal
     * 
     * @param ShiftDetail $shiftDetail
     * @param Carbon $date
     * @return Carbon|null
     */
    private function getWaktuPulangShift($shiftDetail, $date)
    {
        $hari = strtolower($date->locale('id')->isoFormat('dddd'));
        $pulangKey = $hari . '_pulang';

        if (!$shiftDetail->$pulangKey) {
            return null;
        }

        // Parse jam pulang dari shift (format H:i)
        $jamPulang = Carbon::createFromFormat('H:i', $shiftDetail->$pulangKey);

        // Kombinasikan dengan tanggal
        return $date->copy()->setTime($jamPulang->hour, $jamPulang->minute, 0);
    }
}
