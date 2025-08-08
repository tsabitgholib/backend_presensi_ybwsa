<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class AdminUnitHelper
{
    /**
     * Get unit_id based on admin role
     * - If admin_unit: get from admin's unit_id
     * - If super_admin: get from request (body/query)
     * 
     * @param Request $request
     * @param string $unitIdKey Key name for unit_id in request (default: 'unit_id')
     * @return array ['unit_id' => int, 'error' => string|null]
     */
    public static function getUnitId(Request $request, string $unitIdKey = 'unit_id'): array
    {
        $admin = $request->get('admin');
        
        if (!$admin) {
            return ['unit_id' => null, 'error' => 'Admin tidak ditemukan'];
        }

        if ($admin->role === 'admin_unit') {
            $unitId = $admin->unit_id;
            if (!$unitId) {
                return ['unit_id' => null, 'error' => 'Unit tidak ditemukan untuk admin unit ini'];
            }
            return ['unit_id' => $unitId, 'error' => null];
        }

        if ($admin->role === 'super_admin') {
            $unitId = $request->input($unitIdKey);
            if (!$unitId) {
                return ['unit_id' => null, 'error' => "{$unitIdKey} wajib diisi untuk super admin"];
            }
            return ['unit_id' => $unitId, 'error' => null];
        }

        return ['unit_id' => null, 'error' => 'Unauthorized'];
    }

    /**
     * Get unit_detail_ids based on admin role
     * - If admin_unit: get all unit_detail_ids from admin's unit
     * - If super_admin: get from request (body/query)
     * 
     * @param Request $request
     * @param string $unitDetailIdsKey Key name for unit_detail_ids in request (default: 'unit_detail_ids')
     * @return array ['unit_detail_ids' => array, 'error' => string|null]
     */
    public static function getUnitDetailIds(Request $request, string $unitDetailIdsKey = 'unit_detail_ids'): array
    {
        $admin = $request->get('admin');
        
        if (!$admin) {
            return ['unit_detail_ids' => [], 'error' => 'Admin tidak ditemukan'];
        }

        if ($admin->role === 'admin_unit') {
            $unitDetails = \App\Models\UnitDetail::where('unit_id', $admin->unit_id)->get();
            $unitDetailIds = $unitDetails->pluck('id')->toArray();
            return ['unit_detail_ids' => $unitDetailIds, 'error' => null];
        }

        if ($admin->role === 'super_admin') {
            $unitDetailIds = $request->input($unitDetailIdsKey, []);
            if (empty($unitDetailIds)) {
                return ['unit_detail_ids' => [], 'error' => "{$unitDetailIdsKey} wajib diisi untuk super admin"];
            }
            return ['unit_detail_ids' => $unitDetailIds, 'error' => null];
        }

        return ['unit_detail_ids' => [], 'error' => 'Unauthorized'];
    }

    /**
     * Validate that admin has access to the unit
     * 
     * @param Request $request
     * @param int $unitId
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateUnitAccess(Request $request, int $unitId): array
    {
        $admin = $request->get('admin');
        
        if (!$admin) {
            return ['valid' => false, 'error' => 'Admin tidak ditemukan'];
        }

        if ($admin->role === 'admin_unit') {
            if ($admin->unit_id != $unitId) {
                return ['valid' => false, 'error' => 'Tidak memiliki akses ke unit ini'];
            }
            return ['valid' => true, 'error' => null];
        }

        if ($admin->role === 'super_admin') {
            // Super admin can access any unit
            return ['valid' => true, 'error' => null];
        }

        return ['valid' => false, 'error' => 'Unauthorized'];
    }

    /**
     * Validate that admin has access to the unit detail
     * 
     * @param Request $request
     * @param int $unitDetailId
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public static function validateUnitDetailAccess(Request $request, int $unitDetailId): array
    {
        $admin = $request->get('admin');
        
        if (!$admin) {
            return ['valid' => false, 'error' => 'Admin tidak ditemukan'];
        }

        $unitDetail = \App\Models\UnitDetail::find($unitDetailId);
        if (!$unitDetail) {
            return ['valid' => false, 'error' => 'Unit detail tidak ditemukan'];
        }

        return self::validateUnitAccess($request, $unitDetail->unit_id);
    }

    /**
     * Get validation rules for unit_id based on admin role
     * 
     * @param Request $request
     * @param string $unitIdKey Key name for unit_id in request (default: 'unit_id')
     * @return array
     */
    public static function getUnitIdValidationRules(Request $request, string $unitIdKey = 'unit_id'): array
    {
        $admin = $request->get('admin');
        
        if ($admin && $admin->role === 'super_admin') {
            return [$unitIdKey => 'required|exists:unit,id'];
        }
        
        return [];
    }

    /**
     * Get validation rules for unit_detail_ids based on admin role
     * 
     * @param Request $request
     * @param string $unitDetailIdsKey Key name for unit_detail_ids in request (default: 'unit_detail_ids')
     * @return array
     */
    public static function getUnitDetailIdsValidationRules(Request $request, string $unitDetailIdsKey = 'unit_detail_ids'): array
    {
        $admin = $request->get('admin');
        
        if ($admin && $admin->role === 'super_admin') {
            return [
                $unitDetailIdsKey => 'required|array',
                $unitDetailIdsKey . '.*' => 'exists:unit_detail,id'
            ];
        }
        
        return [];
    }
}
