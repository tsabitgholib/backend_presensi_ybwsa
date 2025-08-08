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
            $pegawais = MsPegawai::whereHas('unitDetailPresensi')->get(['id', 'no_ktp', 'nama']);
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
            
            $pegawais = MsPegawai::whereHas('unitDetailPresensi', function ($q) use ($targetUnitId) {
                $q->where('unit_id', $targetUnitId);
            })->get(['id', 'no_ktp', 'nama']);
            
            $unit = Unit::find($targetUnitId);
            $scopeInfo = [
                'type' => 'specific_unit',
                'unit' => $unit ? $unit->name : 'Unit ' . $targetUnitId,
                'unit_id' => $targetUnitId
            ];
        } else {
            return response()->json(['message' => 'Parameter unit_id diperlukan untuk super admin'], 400);
        }

        $noKtps = $pegawais->pluck('no_ktp');

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
    private function getAttendanceSummary($noKtps, $startDate, $endDate)
    {
        $presensi = Presensi::whereIn('no_ktp', $noKtps)
            ->whereBetween('waktu_masuk', [$startDate->toDateString() . ' 00:00:00', $endDate->toDateString() . ' 23:59:59'])
            ->get();

        $totalDays = $startDate->diffInDays($endDate) + 1;
        $totalExpected = count($noKtps) * $totalDays;

        $hadir = $presensi->whereIn('status_presensi', ['hadir', 'dinas'])->count();
        $terlambat = $presensi->where('status_masuk', 'terlambat')->count();
        $tidakHadir = $presensi->where('status_presensi', 'tidak_hadir')->count();
        $izin = $presensi->where('status_presensi', 'izin')->count();
        $sakit = $presensi->where('status_presensi', 'sakit')->count();
        $cuti = $presensi->where('status_presensi', 'cuti')->count();
        $dinas = $presensi->where('status_presensi', 'dinas')->count();

        $attendanceRate = $totalExpected > 0 ? round(($hadir / $totalExpected) * 100, 2) : 0;

        return [
            'total_expected' => $totalExpected,
            'hadir' => $hadir,
            'terlambat' => $terlambat,
            'tidak_hadir' => $tidakHadir,
            'izin' => $izin,
            'sakit' => $sakit,
            'cuti' => $cuti,
            'dinas' => $dinas,
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
        $pegawais = MsPegawai::whereIn('no_ktp', $noKtps)->get(['id', 'no_ktp', 'nama']);

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
                    'id' => $pegawai->id,
                    'nama' => $pegawai->nama,
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
            $total       = $presensi->count();
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

            $pegawais = MsPegawai::whereIn('id', $pegawaiIds)->get(['no_ktp']);
            $noKtps = $pegawais->pluck('no_ktp');

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

            $pegawais = MsPegawai::whereIn('id', $pegawaiIds)->get(['no_ktp']);
            $noKtps = $pegawais->pluck('no_ktp');

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