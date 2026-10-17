<?php

namespace App\Modules\Content\Infrastructure;

use Illuminate\Support\Facades\DB;

final class ContentWriterScopeService
{
    /** @return list<array{id:int,project_key:string,language:string,active:bool}> */
    public function forUser(int $userId): array
    {
        return DB::table('content_writer_scopes')
            ->where('user_id', $userId)
            ->orderBy('project_key')
            ->orderBy('language')
            ->get()
            ->map(fn ($scope): array => [
                'id' => (int) $scope->id,
                'project_key' => (string) $scope->project_key,
                'language' => (string) $scope->language,
                'active' => (bool) $scope->active,
            ])
            ->all();
    }

    public function add(int $userId, string $projectKey, string $language): void
    {
        DB::table('content_writer_scopes')->updateOrInsert(
            ['user_id' => $userId, 'project_key' => $projectKey, 'language' => strtolower($language)],
            ['active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function remove(int $scopeId): void
    {
        DB::table('content_writer_scopes')->where('id', $scopeId)->delete();
    }
}
