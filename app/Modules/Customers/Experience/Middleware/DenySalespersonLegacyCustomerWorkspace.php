<?php

namespace App\Modules\Customers\Experience\Middleware;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DenySalespersonLegacyCustomerWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User &&
            $user->roles()->where('key', RolePreset::SalesRepresentative->roleKey())->exists()) {
            abort(403, 'Use the scoped project Customer OS workspace.');
        }

        return $next($request);
    }
}
