<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Presensi;
use App\Models\MsPegawai;
use App\Models\Unit;
use App\Models\UnitDetail;
use App\Models\PengajuanCuti;
use App\Models\PengajuanSakit;
use App\Models\PengajuanIzin;
use Carbon\Carbon;
use App\Helpers\AdminUnitHelper;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard data for admin
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $admin = $request->get('admin');
        if (!$admin) {
            return response()->json(['message' => 'Admin tidak ditemukan'], 401);
        }

        $bulan = $request->query('bulan', Carbon::now()->month);
        $tahun = $request->query('tahun', Carbon::now()->year);
        $unitId = $request->query('unit_id');

        // Get date range for current month
        $startDate = Carbon::create($tahun, $bulan, 1, 0, 0, 0, 'Asia/Jakarta');
        $endDate = $startDate->copy()->endOfMonth();

        // Determine scope based on admin role and unit_id parameter
        $isSuperAdmin = $admin->role === 'super_admin';
        $isAllUnits = $isSuperAdmin && !$unitId;
        $isSpecificUnit = $unitId || $admin->role === 'admin_unit';

        // Get employees based on scope
        if ($isAllUnits) {
            // Super admin viewing all units
            $pegawais = MsPegawai::with(['orang:id,no_ktp,nama'])
                ->whereHas('unitDetailPresensi')
                ->get(['id', 'id_orang', 'presensi_ms_unit_detail_id']);

            $scopeInfo = [
                'type' => 'all_units',
                'unit' => 'Semua Unit',
                'unit_id' => null
            ];
        } elseif ($isSpecificUnit) {
            // Specific unit (either admin unit or super admin with unit_id)
            $targetUnitId = $unitId ?: $admin->unit_id;
            
            // Validate unit access for super admin
            if ($isSuperAdmin) {
                $unitResult = AdminUnitHelper::validateUnitAccess($request, $targetUnitId);
                if (!$unitResult['valid']) {
                    return response()->json(['message' => $unitResult['error']], 400);
                }
            }
            
            $pegawais = MsPegawai::with(['orang:id,no_ktp,nama'])
                ->whereHas('unitDetailPresensi', function ($q) use ($targetUnitId) {
                    $q->where('ms_unit_id', $targetUnitId);
                })
                ->get(['id', 'id_orang', 'presensi_ms_unit_detail_id']);

            
            $unit = Unit::find($targetUnitId);
            $scopeInfo = [
                'type' => 'specific_unit',
                'unit' => $unit ? $unit->nama : 'Unit ' . $targetUnitId,
                'unit_id' => $targetUnitId
            ];
        } else {
            return response()->json(['message' => 'Parameter unit_id diperlukan untuk super admin'], 400);
        }

        $noKtps = $pegawais->pluck('orang.no_ktp');


        // 1. Total Employees
        $totalPegawai = $pegawais->count();

        // 2. Attendance Summary for Current Month
        $attendanceSummary = $this->getAttendanceSummary($noKtps, $startDate, $endDate);

        // 3. Daily Attendance Chart Data
        $dailyAttendance = $this->getDailyAttendanceData($noKtps, $startDate, $endDate);

        // 4. Status Distribution
        $statusDistribution = $this->getStatusDistribution($noKtps, $startDate, $endDate);

        // 5. Leave Requests Summary
        $leaveRequests = $this->getLeaveRequestsSummary($pegawais->pluck('id'), $startDate, $endDate);

        // 6. Top Employees (Best Attendance)
        $topEmployees = $this->getTopEmployees($noKtps, $startDate, $endDate);

        // 7. Recent Activities
        $recentActivities = $this->getRecentActivities($noKtps, $startDate, $endDate);

        // 8. Shift Distribution
        $shiftDistribution = $this->getShiftDistribution($noKtps, $startDate, $endDate);

        // 9. Monthly Trend (Last 6 months)
        $monthlyTrend = $this->getMonthlyTrend($noKtps, $tahun, $bulan);

        // 10. Unit Performance (for super admin only, when viewing all units)
        $unitPerformance = null;
        if ($isSuperAdmin && $isAllUnits) {
            $unitPerformance = $this->getUnitPerformance($startDate, $endDate);
        }

        // 11. Unit Breakdown (for super admin viewing all units)
        $unitBreakdown = null;
        if ($isSuperAdmin && $isAllUnits) {
            $unitBreakdown = $this->getUnitBreakdown($startDate, $endDate);
        }

        return response()->json([
            'scope' => $scopeInfo,
            'periode' => [
                'bulan' => $bulan,
                'tahun' => $tahun,
                'nama_bulan' => $startDate->locale('id')->isoFormat('MMMM YYYY'),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'ringkasan' => [
                'total_pegawai' => $totalPegawai,
                'ringkasan_presensi' => $attendanceSummary,
                'sisa_pengajuan' => $leaveRequests,
            ],
            'charts' => [
                'jumlah_data_presensi_harian' => $dailyAttendance,
                //'status_distribution' => $statusDistribution,
                //'shift_distribution' => $shiftDistribution,
                'data_bulanan' => $monthlyTrend,
            ],
            'aktifitas' => [
                //'top_employees' => $topEmployees,
                'recent_activities' => $recentActivities,
            ],
            //'unit_performance' => $unitPerformance,
            //'unit_breakdown' => $unitBreakdown,
        ]);
    }

    /**
     * Get attendance summary
     */
    // private function getAttendanceSummary($noKtps, $startDate, $endDate)
    // {
    //     //$presensi = Presensi::whereIn('no_ktp', $noKtps)
    //     //    ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
    //     //    ->get();

    //     $totalDays = $startDate->diffInDays($endDate) + 1;
    //     $totalExpected = count($noKtps) * $totalDays;

    //     // $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
    //     // $terlambat = $presensi->where('status_masuk', 'terlambat')->count();
    //     // $tidakHadir = $presensi->where('status_presensi', 'tidak_hadir')->count();
    //     // $izin = $presensi->where('status_presensi', 'izin')->count();
    //     // $sakit = $presensi->where('status_presensi', 'sakit')->count();
    //     // $cuti = $presensi->where('status_presensi', 'cuti')->count();
    //     // $dinas = $presensi->where('status_presensi', 'dinas')->count();

    //     $summary = Presensi::selectRaw("
    //         SUM(CASE WHEN status_presensi IN ('hadir','dinas') THEN 1 ELSE 0 END) as hadir,
    //         SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
    //         SUM(CASE WHEN status_presensi = 'tidak_hadir' THEN 1 ELSE 0 END) as tidak_hadir,
    //         SUM(CASE WHEN status_presensi = 'izin' THEN 1 ELSE 0 END) as izin,
    //         SUM(CASE WHEN status_presensi = 'sakit' THEN 1 ELSE 0 END) as sakit,
    //         SUM(CASE WHEN status_presensi = 'cuti' THEN 1 ELSE 0 END) as cuti,
    //         SUM(CASE WHEN status_presensi = 'dinas' THEN 1 ELSE 0 END) as dinas,
    //         COUNT(*) as total
    //     ")
    //     ->whereIn('no_ktp', $noKtps)
    //     ->whereBetween('waktu_masuk', [$startDate, $endDate])
    //     ->first();

    //     $totalDays     = $startDate->diffInDays($endDate) + 1;
    //     $totalExpected = count($noKtps) * $totalDays;

    //     $attendanceRate = $totalExpected > 0 
    //         ? round(($summary->hadir / $totalExpected) * 100, 2) 
    //         : 0;

    //     return [
    //         'total_expected' => $totalExpected,
    //         'hadir'          => (int) $summary->hadir,
    //         'terlambat'      => (int) $summary->terlambat,
    //         'tidak_hadir'    => (int) $summary->tidak_hadir,
    //         'izin'           => (int) $summary->izin,
    //         'sakit'          => (int) $summary->sakit,
    //         'cuti'           => (int) $summary->cuti,
    //         'dinas'          => (int) $summary->dinas,
    //         'attendance_rate'=> $attendanceRate,
    //     ];


    //     // $attendanceRate = $totalExpected > 0 ? round(($hadir / $totalExpected) * 100, 2) : 0;

    //     // return [
    //     //     'total_expected' => $totalExpected,
    //     //     'hadir' => $hadir,
    //     //     'terlambat' => $terlambat,
    //     //     'tidak_hadir' => $tidakHadir,
    //     //     'izin' => $izin,
    //     //     'sakit' => $sakit,
    //     //     'cuti' => $cuti,
    //     //     'dinas' => $dinas,
    //     //     'attendance_rate' => $attendanceRate,
    //     // ];
    // }

    private function getAttendanceSummary($noKtps, $startDate, $endDate)
{
    $presensi = Presensi::whereIn('no_ktp', $noKtps)
        ->whereBetween('waktu_masuk', [
            $startDate->toDateString() . ' 00:00:00',
            $endDate->toDateString() . ' 23:59:59'
        ])
        ->get();

    $totalDays     = $startDate->diffInDays($endDate) + 1;
    $totalExpected = count($noKtps) * $totalDays;

    $hadir        = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
    $terlambat    = $presensi->where('status_masuk', 'terlambat')->count();
    $tidakHadir   = $presensi->where('status_presensi', 'tidak_hadir')->count();
    $izin         = $presensi->where('status_presensi', 'izin')->count();
    $sakit        = $presensi->where('status_presensi', 'sakit')->count();
    $cuti         = $presensi->where('status_presensi', 'cuti')->count();
    $dinas        = $presensi->where('status_presensi', 'dinas')->count();

    $attendanceRate = $totalExpected > 0
        ? round(($hadir / $totalExpected) * 100, 2)
        : 0;

    return [
        'total_expected'  => $totalExpected,
        'hadir'           => $hadir,
        'terlambat'       => $terlambat,
        'tidak_hadir'     => $tidakHadir,
        'izin'            => $izin,
        'sakit'           => $sakit,
        'cuti'            => $cuti,
        'dinas'           => $dinas,
        'attendance_rate' => $attendanceRate,
    ];
}


    /**
     * Get daily attendance data for chart
     */
    private function getDailyAttendanceData($noKtps, $startDate, $endDate)
    {
        $data = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $date = $currentDate->format('Y-m-d');
            
            $presensi = Presensi::whereIn('no_ktp', $noKtps)
                ->whereDate('waktu_masuk', $date)
                ->get();

            $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
            $terlambat = $presensi->where('status_masuk', 'terlambat')->count();
            $tidakHadir = $presensi->where('status_presensi', 'tidak_hadir')->count();
            $izin = $presensi->where('status_presensi', 'izin')->count();
            $sakit = $presensi->where('status_presensi', 'sakit')->count();
            $cuti = $presensi->where('status_presensi', 'cuti')->count();

            $data[] = [
                'tanggal' => $date,
                'hari' => $currentDate->locale('id')->isoFormat('dddd'),
                'hadir' => $hadir,
                'terlambat' => $terlambat,
                'tidak_hadir' => $tidakHadir,
                'izin' => $izin,
                'sakit' => $sakit,
                'cuti' => $cuti,
                'total' => $hadir + $terlambat + $tidakHadir + $izin + $sakit + $cuti,
            ];

            $currentDate->addDay();
        }

        return $data;
    }

//     private function getDailyAttendanceData($noKtps, $startDate, $endDate)
//     {
//     $rows = Presensi::selectRaw("
//             DATE(waktu_masuk) as tanggal,
//             SUM(CASE WHEN status_presensi IN ('hadir','dinas') THEN 1 ELSE 0 END) as hadir,
//             SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
//             SUM(CASE WHEN status_presensi = 'tidak_hadir' THEN 1 ELSE 0 END) as tidak_hadir,
//             SUM(CASE WHEN status_presensi = 'izin' THEN 1 ELSE 0 END) as izin,
//             SUM(CASE WHEN status_presensi = 'sakit' THEN 1 ELSE 0 END) as sakit,
//             SUM(CASE WHEN status_presensi = 'cuti' THEN 1 ELSE 0 END) as cuti,
//             COUNT(*) as total
//         ")
//         ->whereIn('no_ktp', $noKtps)
//         ->whereBetween('waktu_masuk', [$startDate, $endDate])
//         ->groupBy(DB::raw('DATE(waktu_masuk)'))
//         ->orderBy('tanggal')
//         ->get()
//         ->keyBy('tanggal');


//     $data = [];
//     $currentDate = $startDate->copy();
//     while ($currentDate->lte($endDate)) {
//         $date = $currentDate->format('Y-m-d');
//         $row = $rows->get($date);

//         $data[] = [
//             'tanggal'     => $date,
//             'hari'        => $currentDate->locale('id')->isoFormat('dddd'),
//             'hadir'       => $row ? (int)$row->hadir : 0,
//             'terlambat'   => $row ? (int)$row->terlambat : 0,
//             'tidak_hadir' => $row ? (int)$row->tidak_hadir : 0,
//             'izin'        => $row ? (int)$row->izin : 0,
//             'sakit'       => $row ? (int)$row->sakit : 0,
//             'cuti'        => $row ? (int)$row->cuti : 0,
//             'total'       => $row ? (int)$row->total : 0,
//         ];

//         $currentDate->addDay();
//     }

//     return $data;
// }



    /**
     * Get status distribution for pie chart
     */
    private function getStatusDistribution($noKtps, $startDate, $endDate)
    {
        $presensi = Presensi::whereIn('no_ktp', $noKtps)
            ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
            ->get();

            $distribution = [
                ['status' => 'Hadir', 'count' => $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count()],
                ['status' => 'Terlambat', 'count' => $presensi->where('status_masuk', 'terlambat')->count()],
                ['status' => 'Tidak Hadir', 'count' => $presensi->where('status_presensi', 'tidak_hadir')->count()],
                ['status' => 'Izin', 'count' => $presensi->where('status_presensi', 'izin')->count()],
                ['status' => 'Sakit', 'count' => $presensi->where('status_presensi', 'sakit')->count()],
                ['status' => 'Cuti', 'count' => $presensi->where('status_presensi', 'cuti')->count()],
            ];


        return array_filter($distribution, function ($item) {
            return $item['count'] > 0;
        });
    }

    /**
     * Get leave requests summary
     */
    private function getLeaveRequestsSummary($pegawaiIds, $startDate, $endDate)
    {
        $cuti = PengajuanCuti::whereIn('pegawai_id', $pegawaiIds)
            ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
            ->count();

        $sakit = PengajuanSakit::whereIn('pegawai_id', $pegawaiIds)
            ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
            ->count();

        $izin = PengajuanIzin::whereIn('pegawai_id', $pegawaiIds)
            ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
            ->count();

        return [
            'cuti' => $cuti,
            'sakit' => $sakit,
            'izin' => $izin,
            'total' => $cuti + $sakit + $izin,
        ];
    }

    /**
     * Get top employees with best attendance
     */
    private function getTopEmployees($noKtps, $startDate, $endDate)
    {
        $pegawais = MsPegawai::with('orang:id,no_ktp,nama')
            ->whereHas('orang', function ($q) use ($noKtps) {
                $q->whereIn('no_ktp', $noKtps);
            })
            ->get(['id', 'id_orang']);


        $employeeStats = [];
        foreach ($pegawais as $pegawai) {
            $presensi = Presensi::where('no_ktp', $pegawai->no_ktp)
                ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
                ->get();

            $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
            $terlambat = $presensi->where('status_masuk', 'terlambat')->count();
            $total = $presensi->count();

            if ($total > 0) {
                $attendanceRate = round(($hadir / $total) * 100, 2);
                $employeeStats[] = [
                    'id' => $pegawai->orang->id,
                    'nama' => $pegawai->orang->nama,
                    'hadir' => $hadir,
                    'terlambat' => $terlambat,
                    'total' => $total,
                    'attendance_rate' => $attendanceRate,
                ];
            }
        }

        // Sort by attendance rate descending
        usort($employeeStats, function ($a, $b) {
            return $b['attendance_rate'] <=> $a['attendance_rate'];
        });

        return array_slice($employeeStats, 0, 10); // Top 10
    }

//     private function getTopEmployees($noKtps, $startDate, $endDate)
// {
//     $stats = Presensi::selectRaw("
//             no_ktp,
//             SUM(CASE WHEN status_presensi IN ('hadir','dinas') THEN 1 ELSE 0 END) as hadir,
//             SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
//             COUNT(*) as total
//         ")
//         ->whereIn('no_ktp', $noKtps)
//         ->whereBetween('waktu_masuk', [
//             $startDate->toDateString() . ' 00:00:00',
//             $endDate->toDateString() . ' 23:59:59'
//         ])
//         ->groupBy('no_ktp')
//         ->orderByDesc(DB::raw('hadir / total'))
//         ->limit(10)
//         ->get();

//     // Ambil data pegawai
//     $pegawaiMap = MsPegawai::whereIn('no_ktp', $stats->pluck('no_ktp'))
//         ->pluck('nama', 'no_ktp');

//     // Gabung data presensi dengan nama pegawai + attendance rate
//     return $stats->map(function ($row) use ($pegawaiMap) {
//         return [
//             'no_ktp'          => $row->no_ktp,
//             'nama'            => $pegawaiMap[$row->no_ktp] ?? null,
//             'hadir'           => (int) $row->hadir,
//             'terlambat'       => (int) $row->terlambat,
//             'total'           => (int) $row->total,
//             'attendance_rate' => round(($row->hadir / $row->total) * 100, 2),
//         ];
//     })->values();
// }


    /**
     * Get recent activities
     */
    private function getRecentActivities($noKtps, $startDate, $endDate)
    {
        $activities = [];

        // Recent presensi
        $recentPresensi = Presensi::whereIn('no_ktp', $noKtps)
            ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
            ->with(['pegawai'])
            ->orderBy('waktu_masuk', 'desc')
            ->limit(10)
            ->get();

        foreach ($recentPresensi as $presensi) {
            $activities[] = [
                'type' => 'presensi',
                'pegawai' => $presensi->pegawai->nama,
                'status' => $presensi->status_presensi,
                'waktu' => $presensi->waktu_masuk->format('Y-m-d H:i:s'),
                'tanggal' => $presensi->waktu_masuk->format('Y-m-d'),
                'jam' => $presensi->waktu_masuk->format('H:i'),
            ];
        }

        // Sort by time descending
        usort($activities, function ($a, $b) {
            return strtotime($b['waktu']) <=> strtotime($a['waktu']);
        });

        return array_slice($activities, 0, 10); // Top 10
    }

    /**
     * Get shift distribution
     */
    private function getShiftDistribution($noKtps, $startDate, $endDate)
    {
        $presensi = Presensi::whereIn('no_ktp', $noKtps)
            ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
            ->with(['shift'])
            ->get();

        $shiftCounts = $presensi->groupBy('shift.name')->map(function ($group) {
            return $group->count();
        });

        $distribution = [];
        foreach ($shiftCounts as $shiftName => $count) {
            $distribution[] = [
                'shift' => $shiftName ?: 'Tidak Ada Shift',
                'count' => $count,
            ];
        }

        return $distribution;
    }

    private function getMonthlyTrend($noKtps, $currentYear, $currentMonth)
    {
        $trend = [];

        // Loop dari Januari sampai bulan sekarang
        for ($month = 1; $month <= $currentMonth; $month++) {
            $date = Carbon::create($currentYear, $month, 1);
            $startDate = $date->copy()->startOfMonth();
            $endDate = $date->copy()->endOfMonth();

            $presensi = Presensi::whereIn('no_ktp', $noKtps)
                ->whereBetween('waktu_masuk', [
                    $startDate->toDateString() . ' 00:00:00',
                    $endDate->toDateString() . ' 23:59:59'
                ])
                ->get();

            $hadir       = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
            $tidakHadir  = $presensi->where('status_presensi', 'tidak_hadir')->count();
            $izin        = $presensi->where('status_presensi', 'izin')->count();
            $sakit       = $presensi->where('status_presensi', 'sakit')->count();
            $cuti        = $presensi->where('status_presensi', 'cuti')->count();
            $dinas       = $presensi->where('status_presensi', 'dinas')->count();
            $total = $hadir + $tidakHadir + $izin + $sakit + $cuti + $dinas;
            $attendanceRate = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;

            $trend[] = [
                'bulan'           => $date->format('Y-m'),
                'nama_bulan'      => $date->locale('id')->isoFormat('MMM YYYY'),
                'hadir'           => $hadir,
                'tidak_hadir'     => $tidakHadir,
                'izin'            => $izin,
                'sakit'           => $sakit,
                'cuti'            => $cuti,
                'dinas'           => $dinas,
                'total'           => $total,
                'attendance_rate' => $attendanceRate,
            ];
        }

        return $trend;
    }

//     private function getMonthlyTrend($noKtps, $currentYear, $currentMonth)
// {
//     $raw = Presensi::selectRaw("
//             DATE_FORMAT(waktu_masuk, '%Y-%m') as bulan,
//             SUM(CASE WHEN status_presensi IN ('hadir','dinas') THEN 1 ELSE 0 END) as hadir,
//             SUM(CASE WHEN status_presensi = 'tidak_hadir' THEN 1 ELSE 0 END) as tidak_hadir,
//             SUM(CASE WHEN status_presensi = 'izin' THEN 1 ELSE 0 END) as izin,
//             SUM(CASE WHEN status_presensi = 'sakit' THEN 1 ELSE 0 END) as sakit,
//             SUM(CASE WHEN status_presensi = 'cuti' THEN 1 ELSE 0 END) as cuti,
//             SUM(CASE WHEN status_presensi = 'dinas' THEN 1 ELSE 0 END) as dinas,
//             COUNT(*) as total
//         ")
//         ->whereIn('no_ktp', $noKtps)
//         ->whereYear('waktu_masuk', $currentYear)
//         ->groupBy('bulan')
//         ->orderBy('bulan')
//         ->get()
//         ->keyBy('bulan');

//     $trend = [];

//     // Loop dari Januari sampai bulan sekarang supaya bulan kosong tetap muncul
//     for ($month = 1; $month <= $currentMonth; $month++) {
//         $date = Carbon::create($currentYear, $month, 1);
//         $key  = $date->format('Y-m');

//         $row = $raw->get($key);

//         $hadir      = $row->hadir ?? 0;
//         $tidakHadir = $row->tidak_hadir ?? 0;
//         $izin       = $row->izin ?? 0;
//         $sakit      = $row->sakit ?? 0;
//         $cuti       = $row->cuti ?? 0;
//         $dinas      = $row->dinas ?? 0;
//         $total      = $row->total ?? 0;
//         $attendanceRate = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;

//         $trend[] = [
//             'bulan'           => $key,
//             'nama_bulan'      => $date->locale('id')->isoFormat('MMM YYYY'),
//             'hadir'           => (int) $hadir,
//             'tidak_hadir'     => (int) $tidakHadir,
//             'izin'            => (int) $izin,
//             'sakit'           => (int) $sakit,
//             'cuti'            => (int) $cuti,
//             'dinas'           => (int) $dinas,
//             'total'           => (int) $total,
//             'attendance_rate' => $attendanceRate,
//         ];
//     }

//     return $trend;
// }



    /**
     * Get unit performance (for super admin only)
     */
    private function getUnitPerformance($startDate, $endDate)
    {
        $units = Unit::with(['unitDetails.pegawaisPresensi'])->get();
        $performance = [];

        foreach ($units as $unit) {
            $pegawaiIds = $unit->unitDetails->flatMap(function ($unitDetail) {
                return $unitDetail->pegawaisPresensi->pluck('id');
            });

            $pegawais = MsPegawai::with('orang')
                ->whereIn('id', $pegawaiIds)
                ->get();

            $noKtps = $pegawais->pluck('orang.no_ktp')->filter()->values();


            if ($noKtps->count() > 0) {
                $presensi = Presensi::whereIn('no_ktp', $noKtps)
                    ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
                    ->get();

                $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
                $total = $presensi->count();
                $attendanceRate = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;

                $performance[] = [
                    'unit_id' => $unit->id,
                    'unit_name' => $unit->name,
                    'total_pegawai' => $noKtps->count(),
                    'hadir' => $hadir,
                    'total_presensi' => $total,
                    'attendance_rate' => $attendanceRate,
                ];
            }
        }

        // Sort by attendance rate descending
        usort($performance, function ($a, $b) {
            return $b['attendance_rate'] <=> $a['attendance_rate'];
        });

        return $performance;
    }

    /**
     * Get unit breakdown (for super admin viewing all units)
     */
    private function getUnitBreakdown($startDate, $endDate)
    {
        $units = Unit::with(['unitDetails.pegawaisPresensi'])->get();
        $breakdown = [];

        foreach ($units as $unit) {
            $pegawaiIds = $unit->unitDetails->flatMap(function ($unitDetail) {
                return $unitDetail->pegawaisPresensi->pluck('id');
            });

            $pegawais = MsPegawai::with('orang')
                ->whereIn('id', $pegawaiIds)
                ->get();

            $noKtps = $pegawais->pluck('orang.no_ktp')->filter()->values();


            if ($noKtps->count() > 0) {
                $presensi = Presensi::whereIn('no_ktp', $noKtps)
                    ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
                    ->get();

                $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
                $terlambat = $presensi->where('status_masuk', 'terlambat')->count();
                $tidakHadir = $presensi->where('status_presensi', 'tidak_hadir')->count();
                $izin = $presensi->where('status_presensi', 'izin')->count();
                $sakit = $presensi->where('status_presensi', 'sakit')->count();
                $cuti = $presensi->where('status_presensi', 'cuti')->count();
                $dinas = $presensi->where('status_presensi', 'dinas')->count();
                $total = $presensi->count();

                $attendanceRate = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;

                // Get leave requests for this unit
                $cutiRequests = PengajuanCuti::whereIn('pegawai_id', $pegawaiIds)
                    ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
                    ->count();

                $sakitRequests = PengajuanSakit::whereIn('pegawai_id', $pegawaiIds)
                    ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
                    ->count();

                $izinRequests = PengajuanIzin::whereIn('pegawai_id', $pegawaiIds)
                    ->whereBetween('tanggal_mulai', [$startDate->toDateString(), $endDate->toDateString()])
                    ->count();

                $breakdown[] = [
                    'unit_id' => $unit->id,
                    'unit_name' => $unit->name,
                    'total_pegawai' => $noKtps->count(),
                    'attendance_summary' => [
                        'hadir' => $hadir,
                        'terlambat' => $terlambat,
                        'tidak_hadir' => $tidakHadir,
                        'izin' => $izin,
                        'sakit' => $sakit,
                        'cuti' => $cuti,
                        'dinas' => $dinas,
                        'total_presensi' => $total,
                        'attendance_rate' => $attendanceRate,
                    ],
                    'leave_requests' => [
                        'cuti' => $cutiRequests,
                        'sakit' => $sakitRequests,
                        'izin' => $izinRequests,
                        'total' => $cutiRequests + $sakitRequests + $izinRequests,
                    ],
                ];
            }
        }

        // Sort by attendance rate descending
        usort($breakdown, function ($a, $b) {
            return $b['attendance_summary']['attendance_rate'] <=> $a['attendance_summary']['attendance_rate'];
        });

        return $breakdown;
    }
}