<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthAdminController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\PresensiLokasiController;
use App\Http\Controllers\AuthPegawaiController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\UnitDetailController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PegawaiController;
use App\Http\Controllers\PresensiController;
use App\Http\Controllers\PengajuanCutiController;
use App\Http\Controllers\PengajuanSakitController;
use App\Http\Controllers\PengajuanIzinController;
use App\Http\Controllers\LaukPaukUnitController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('admin/login', [AuthAdminController::class, 'login']);
Route::post('pegawai/login', [AuthPegawaiController::class, 'login']);
Route::middleware(['auth.jwt'])->group(function () {
    Route::get('admin/me', [AuthAdminController::class, 'me']);
    Route::get('pegawai/me', [AuthPegawaiController::class, 'me']);

    // Admin
    Route::get('admin', [AdminController::class, 'index']);
    Route::post('admin/create', [AdminController::class, 'store']);
    Route::put('admin/update/{id}', [AdminController::class, 'update']);
    Route::delete('admin/delete/{id}', [AdminController::class, 'destroy']);

    // Unit
    Route::get('unit', [UnitController::class, 'index']);
    Route::post('unit/create', [UnitController::class, 'store']);
    Route::put('unit/update/{id}', [UnitController::class, 'update']);
    Route::delete('unit/delete/{id}', [UnitController::class, 'destroy']);

    // Unit Detail
    Route::get('unit-detail', [UnitDetailController::class, 'getAll']);
    Route::get('unit-detail/{unit_id}', [UnitDetailController::class, 'index']);
    Route::post('unit-detail/create', [UnitDetailController::class, 'store']);
    Route::put('unit-detail/update/{id}', [UnitDetailController::class, 'update']);
    Route::delete('unit-detail/delete/{id}', [UnitDetailController::class, 'destroy']);
    Route::get('unit-detail/get-by-id/{id}', [UnitDetailController::class, 'show']);
    Route::post('unit-detail/add-pegawai-to-unit-detail', [\App\Http\Controllers\UnitDetailController::class, 'assignPegawai']);

    // Pegawai
    Route::get('pegawai', [PegawaiController::class, 'index']);
    Route::post('pegawai/create', [PegawaiController::class, 'store']);
    Route::put('pegawai/update/{id}', [PegawaiController::class, 'update']);
    Route::delete('pegawai/delete/{id}', [PegawaiController::class, 'destroy']);
    Route::get('pegawai/by-unit-id-presensi', [PegawaiController::class, 'getByUnitIdPresensi']);
    Route::get('pegawai/lokasi-presensi', [PegawaiController::class, 'getLokasiPresensi']);
    Route::get('pegawai/cek-hari-libur', [PegawaiController::class, 'cekHariLibur']);

    // Shift
    Route::get('shift', [ShiftController::class, 'index']);
    Route::post('shift/create', [ShiftController::class, 'store']);
    Route::put('shift/update/{id}', [ShiftController::class, 'update']);
    Route::delete('shift/delete/{id}', [ShiftController::class, 'destroy']);

    // Shift Detail
    Route::post('shift-detail/create', [ShiftController::class, 'storeDetail']);
    Route::put('shift-detail/update/{id}', [ShiftController::class, 'updateDetail']);
    Route::delete('shift-detail/delete/{id}', [ShiftController::class, 'destroyDetail']);
    Route::get('shift-detail/unit/{unit_id}', [ShiftController::class, 'getByUnit']);
    Route::post('shift-detail/add-pegawai-to-shift-detail', [ShiftController::class, 'assignPegawaiToShiftDetail']);
    Route::get('shift-detail/get-by-id/{id}', [ShiftController::class, 'getShiftDetailById']);

    // Presensi
    Route::post('presensi', [PresensiController::class, 'store']);
    Route::get('presensi/today', [PresensiController::class, 'today']);
    Route::get('presensi/history', [PresensiController::class, 'history']);
    Route::get('presensi/rekap-by-unit', [PresensiController::class, 'rekapPresensiByAdminUnit']);
    Route::get('presensi/history-by-unit', [PresensiController::class, 'historyByAdminUnit']);
    Route::get('presensi/monthly-recap', [\App\Http\Controllers\PresensiController::class, 'rekapHistoryBulananPegawai']);
    Route::get('presensi/detail-history-by-unit', [\App\Http\Controllers\PresensiController::class, 'detailHistoryByAdminUnit']);
    Route::put('presensi/update-by-admin-unit/{pegawai_id}/{tanggal}', [\App\Http\Controllers\PresensiController::class, 'updatePresensiByAdminUnitBulk']);
    Route::get('presensi/rekap-bulanan-pegawai', [PresensiController::class, 'rekapBulananByPegawai']);
    Route::get('presensi/rekap-bulanan-semua-pegawai', [PresensiController::class, 'rekapPresensiBulananByAdminUnit']);

    // Izin
    Route::get('izin', [\App\Http\Controllers\IzinController::class, 'index']);
    Route::post('izin/create', [\App\Http\Controllers\IzinController::class, 'store']);
    Route::get('izin/get-by-id/{id}', [\App\Http\Controllers\IzinController::class, 'show']);
    Route::put('izin/update/{id}', [\App\Http\Controllers\IzinController::class, 'update']);
    Route::delete('izin/delete/{id}', [\App\Http\Controllers\IzinController::class, 'destroy']);

    // Cuti
    Route::get('cuti', [\App\Http\Controllers\CutiController::class, 'index']);
    Route::post('cuti/create', [\App\Http\Controllers\CutiController::class, 'store']);
    Route::get('cuti/get-by-id/{id}', [\App\Http\Controllers\CutiController::class, 'show']);
    Route::put('cuti/update/{id}', [\App\Http\Controllers\CutiController::class, 'update']);
    Route::delete('cuti/delete/{id}', [\App\Http\Controllers\CutiController::class, 'destroy']);

    // Sakit
    Route::get('sakit', [\App\Http\Controllers\SakitController::class, 'index']);
    Route::post('sakit/create', [\App\Http\Controllers\SakitController::class, 'store']);
    Route::get('sakit/get-by-id/{id}', [\App\Http\Controllers\SakitController::class, 'show']);
    Route::put('sakit/update/{id}', [\App\Http\Controllers\SakitController::class, 'update']);
    Route::delete('sakit/delete/{id}', [\App\Http\Controllers\SakitController::class, 'destroy']);

    // Pengajuan Cuti
    Route::post('/pengajuan-cuti', [PengajuanCutiController::class, 'store']);
    Route::get('/pengajuan-cuti', [PengajuanCutiController::class, 'index']);
    Route::post('/pengajuan-cuti/approve/{id}', [PengajuanCutiController::class, 'approve']);

    // Pengajuan Sakit
    Route::post('/pengajuan-sakit', [PengajuanSakitController::class, 'store']);
    Route::get('/pengajuan-sakit', [PengajuanSakitController::class, 'index']);
    Route::post('/pengajuan-sakit/approve/{id}', [PengajuanSakitController::class, 'approve']);

    // Pengajuan Izin
    Route::post('/pengajuan-izin', [PengajuanIzinController::class, 'store']);
    Route::get('/pengajuan-izin', [PengajuanIzinController::class, 'index']);
    Route::post('/pengajuan-izin/approve/{id}', [PengajuanIzinController::class, 'approve']);

    // History pengajuan oleh pegawai
    Route::get('/pengajuan-izin/history', [PengajuanIzinController::class, 'history']);
    Route::get('/pengajuan-cuti/history', [PengajuanCutiController::class, 'history']);
    Route::get('/pengajuan-sakit/history', [PengajuanSakitController::class, 'history']);

    // Hari Libur
    Route::get('hari-libur', [\App\Http\Controllers\HariLiburController::class, 'index']);
    Route::post('hari-libur/create', [\App\Http\Controllers\HariLiburController::class, 'store']);
    Route::post('hari-libur/multiple-create', [\App\Http\Controllers\HariLiburController::class, 'storeMultiple']);
    Route::put('hari-libur/multiple-update', [\App\Http\Controllers\HariLiburController::class, 'updateMultiple']);
    Route::delete('hari-libur/multiple-delete', [\App\Http\Controllers\HariLiburController::class, 'deleteMultiple']);

    // Lauk Pauk Unit
    Route::get('lauk-pauk', [\App\Http\Controllers\LaukPaukUnitController::class, 'index']);
    Route::get('lauk-pauk/by-admin-unit', [\App\Http\Controllers\LaukPaukUnitController::class, 'showByAdminUnit']);
    Route::get('lauk-pauk-unit/get-by-id/{id}', [\App\Http\Controllers\LaukPaukUnitController::class, 'show']);
    Route::post('lauk-pauk-unit/create', [\App\Http\Controllers\LaukPaukUnitController::class, 'store']);
    Route::put('lauk-pauk-unit/update/{id}', [\App\Http\Controllers\LaukPaukUnitController::class, 'update']);
    Route::delete('lauk-pauk-unit/delete/{id}', [\App\Http\Controllers\LaukPaukUnitController::class, 'destroy']);
});
