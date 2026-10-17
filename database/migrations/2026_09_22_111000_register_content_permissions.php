<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'content.view' => '查看内容生产',
        'content.write' => '领取并撰写内容',
        'content.review' => '审核内容',
        'content.publish' => '发布内容',
        'content.manage' => '管理内容生产',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $label, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $this->attach('role_preset_writer', ['content.view', 'content.write']);
        $this->attach('role_preset_operations', array_keys(self::PERMISSIONS));
        $this->attach('role_preset_administrator', array_keys(self::PERMISSIONS));

        $writerRoleId = DB::table('roles')->where('key', 'role_preset_writer')->value('id');
        if ($writerRoleId) {
            $legacyGuideIds = DB::table('permissions')
                ->whereIn('key', ['guides.view', 'guides.manage'])
                ->pluck('id');
            DB::table('permission_role')
                ->where('role_id', $writerRoleId)
                ->whereIn('permission_id', $legacyGuideIds)
                ->delete();
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        $writerRoleId = DB::table('roles')->where('key', 'role_preset_writer')->value('id');
        if ($writerRoleId) {
            $legacyGuideIds = DB::table('permissions')
                ->whereIn('key', ['guides.view', 'guides.manage'])
                ->pluck('id');
            foreach ($legacyGuideIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $writerRoleId,
                ], []);
            }
        }
    }

    /** @param list<string> $permissionKeys */
    private function attach(string $roleKey, array $permissionKeys): void
    {
        $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('key', $permissionKeys)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ], []);
        }
    }
};
