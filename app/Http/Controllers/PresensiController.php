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
        // // Ambil shift dan unit_detail
        // $shift = $shiftDetail->shift;
        $unitDetail = $pegawai->unitDetailPresensi;
        if (!$unitDetail) {
            return response()->json(['message' => 'Unit detail tidak ditemukan'], 400);
        }
        // Validasi lokasi (point-in-polygon)
        $polygon = $unitDetail->lokasi;
        if (!$this->isPointInPolygon($request->lokasi, $polygon)) {
            return response()->json(['message' => 'Lokasi di luar area'], 400);
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
        $waktuSekarang = $now->format('H:i');
        $status = null;
        $keterangan = null;
        // Cek apakah sudah ada absen masuk hari ini
        $presensiHariIni = Presensi::where('no_ktp', $pegawai->no_ktp)
            ->whereDate('waktu', $now->toDateString())
            ->get();
        $sudahAbsenMasuk = $presensiHariIni->whereIn('status', ['absen_masuk', 'terlambat'])->count() > 0;
        $jam12 = \Carbon\Carbon::createFromTime(12, 0, 0, 'Asia/Jakarta');
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
            'lokasi' => $request->lokasi,
            'keterangan' => $keterangan,
        ]);
        // Ambil shift_name
        $shift_name = $shift ? $shift->name : null;
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
        $masuk = $presensi->whereIn('status', ['absen_masuk', 'terlambat'])->first();
        $keluar = $presensi->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
        return response()->json([
            'tanggal' => $today,
            'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
            'status_masuk' => $masuk ? $masuk->status : null,
            'status_keluar' => $keluar ? $keluar->status : null,
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
            $masuk = $presensi->whereIn('status', ['absen_masuk', 'terlambat'])->first();
            $keluar = $presensi->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
            return response()->json([
                'hari' => \Carbon\Carbon::parse($tanggal)->locale('id')->isoFormat('dddd'),
                'tanggal' => $tanggal,
                'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'status_masuk' => $masuk ? $masuk->status : null,
                'status_keluar' => $keluar ? $keluar->status : null,
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
            $keluar = $items->whereIn('status', ['absen_pulang', 'pulang_awal'])->last();
            $history[] = [
                'hari' => \Carbon\Carbon::parse($tanggal)->locale('id')->isoFormat('dddd'),
                'tanggal' => $tanggal,
                'jam_masuk' => $masuk ? $masuk->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'jam_keluar' => $keluar ? $keluar->waktu->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('H:i:s') : null,
                'status_masuk' => $masuk ? $masuk->status : null,
                'status_keluar' => $keluar ? $keluar->status : null,
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
        $pegawais = MsPegawai::where('unit_detail_id_presensi', $admin->unit_id)->get();
        $result = [];
        foreach ($pegawais as $pegawai) {
            $query = Presensi::where('no_ktp', $pegawai->no_ktp);
            if ($tanggal) {
                $query->whereDate('waktu', $tanggal);
            }
            $presensis = $query->get();
            $total_hadir = $presensis->whereIn('status', ['absen_masuk', 'absen_pulang', 'terlambat'])->count();
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
        $pegawais = MsPegawai::where('unit_detail_id_presensi', $admin->unit_id)->get(['id', 'no_ktp', 'nama_depan', 'nama_belakang']);
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
}
