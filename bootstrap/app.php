<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ObserveErpRequest;
use App\Modules\Identity\Experience\Console\Commands\CreateErpUser;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Experience\Middleware\EnsureActiveAuthenticatedUser;
use App\Modules\Identity\Experience\Middleware\RequireAnyPermission;
use App\Modules\Logistics\Experience\Console\Commands\AutomaticAllocationCommand;
use App\Modules\Runtime\Experience\Console\Commands\RuntimeHealthCommand;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateErpUser::class,
        RuntimeHealthCommand::class,
        AutomaticAllocationCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->web(append: [
            ApplyLocalAdminBypass::class,
            EnsureActiveAuthenticatedUser::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->prepend(ObserveErpRequest::class);
        $middleware->alias(['any.permission' => RequireAnyPermission::class]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ApplyLocalAdminBypass::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($request->expectsJson() || ! $request->header('X-Inertia') || config('app.debug') || $status < 500) {
                return null;
            }

            $errorId = (string) $request->attributes->get('erp_request_id');

            // Record only the 5xx rendered by this Inertia error boundary. Laravel's
            // default reporter deliberately ignores some HTTP exceptions (including 5xx).
            // Never log exception messages, arguments, input, identities or headers.
            try {
                $path = $exception->getFile();
                $prefix = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $safeFile = str_starts_with($path, $prefix)
                    ? substr($path, strlen($prefix))
                    : basename($path);

                Log::error('erp.request.inertia_5xx', [
                    'error_id' => $errorId,
                    'status' => $status,
                    'exception_class' => $exception::class,
                    'exception_file' => $safeFile,
                    'exception_line' => $exception->getLine(),
                    'route' => $request->route()?->getName(),
                    'method' => $request->method(),
                ]);
            } catch (Throwable) {
                // Logging failures must not prevent the safe public error page.
            }

            $response = Inertia::render('Errors/ServerError', [
                'errorId' => $errorId,
            ])->toResponse($request);
            $response->setStatusCode($status);
            $response->headers->set((string) config('observability.request_id_header'), $errorId);

            return $response;
        });
        $exceptions->respond(function (Response $response): Response {
            $requestId = request()->attributes->get('erp_request_id');
            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set((string) config('observability.request_id_header'), $requestId);
            }

            return $response;
        });
    })->create();
