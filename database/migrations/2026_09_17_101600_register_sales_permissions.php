<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'sales.view' => '查看销售',
        'sales.operate' => '操作销售',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $label) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['label' => $label, 'created_at' => $now, 'updated_at' => $now]);
        }

        $roleKeys = ['role_preset_administrator', 'role_preset_sales_representative', 'role_preset_operations'];
        $roleIds = DB::table('roles')->whereIn('key', $roleKeys)->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(['permission_id' => $permissionId, 'role_id' => $roleId], []);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
