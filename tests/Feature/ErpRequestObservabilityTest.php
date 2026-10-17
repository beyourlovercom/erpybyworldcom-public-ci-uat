<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class ErpRequestObservabilityTest extends TestCase
{
    public function test_valid_request_id_is_reused_and_returned(): void
    {
        Route::get('/testing/observability', fn () => response()->json(['ok' => true]));

        $this->get('/testing/observability', ['X-ERP-Request-ID' => 'test-request-123'])
            ->assertOk()
            ->assertHeader('X-ERP-Request-ID', 'test-request-123');
    }

    public function test_invalid_request_id_is_replaced_with_safe_generated_id(): void
    {
        Route::get('/testing/observability-invalid', fn () => response()->json(['ok' => true]));

        $response = $this->get('/testing/observability-invalid', ['X-ERP-Request-ID' => 'bad id']);

        $response->assertOk();
        $requestId = (string) $response->headers->get('X-ERP-Request-ID');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{8,128}$/', $requestId);
        $this->assertNotSame('bad id', $requestId);
    }

    public function test_inertia_500_is_safe_and_contains_error_id(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/inertia-error', fn () => throw new \RuntimeException('secret exception details'));

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'test-inertia-500',
        ])->get('/testing/inertia-error');

        $response->assertStatus(500)
            ->assertHeader('X-ERP-Request-ID', 'test-inertia-500')
            ->assertJsonPath('component', 'Errors/ServerError')
            ->assertJsonPath('props.errorId', 'test-inertia-500')
            ->assertJsonMissing(['message' => 'secret exception details']);
    }

    public function test_performance_warning_records_breaches_budgets_and_release_revision(): void
    {
        config()->set('observability.query_budget', 0);
        config()->set('observability.query_time_budget_ms', 0);
        config()->set('observability.duration_budget_ms', 0);
        Log::spy();

        Route::get('/testing/observability-budget', function () {
            DB::select('select 1');

            return response()->json(['ok' => true]);
        });

        $this->get('/testing/observability-budget', ['X-ERP-Request-ID' => 'test-budget-123'])
            ->assertOk()
            ->assertHeader('X-ERP-Request-ID', 'test-budget-123');

        Log::shouldHaveReceived('warning')->with(
            'erp.request.performance_budget_exceeded',
            Mockery::on(function (array $context): bool {
                return $context['request_id'] === 'test-budget-123'
                    && is_string($context['revision'] ?? null)
                    && ($context['revision'] ?? '') !== ''
                    && in_array('query_count', $context['breaches'] ?? [], true)
                    && ($context['budgets']['query_count'] ?? null) === 0;
            }),
        )->once();
    }

    public function test_non_500_http_status_is_not_rewritten_as_inertia_500(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/not-found', fn () => abort(404));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->get('/testing/not-found')->assertNotFound();
    }

    public function test_inertia_500_is_recorded_at_render_boundary_with_safe_correlation(): void
    {
        config()->set('app.debug', false);
        Log::spy();
        Route::get('/testing/p0-logger', fn () => throw new \RuntimeException('secret exception details'));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'p0-diagnostic-canary',
        ])->get('/testing/p0-logger')
            ->assertStatus(500)
            ->assertJsonPath('props.errorId', 'p0-diagnostic-canary');

        Log::shouldHaveReceived('error')->with(
            'erp.request.inertia_5xx',
            Mockery::on(fn (array $data): bool => ($data['error_id'] ?? '') === 'p0-diagnostic-canary'
                && ($data['status'] ?? null) === 500
                && ($data['exception_class'] ?? '') === \RuntimeException::class
                && str_contains((string) ($data['exception_file'] ?? ''), 'ErpRequestObservabilityTest.php')
                && is_int($data['exception_line'] ?? null)
                && ($data['method'] ?? '') === 'GET'
                && ! array_key_exists('exception_message', $data)
                && ! array_key_exists('password', $data))
        )->once();
    }

    public function test_inertia_http_503_is_logged_even_when_laravel_skips_http_exception_reports(): void
    {
        config()->set('app.debug', false);
        Log::spy();
        Route::get('/testing/p0-http-503', fn () => abort(503));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'p0-http-503-test',
        ])->get('/testing/p0-http-503')
            ->assertStatus(503)
            ->assertJsonPath('props.errorId', 'p0-http-503-test');

        Log::shouldHaveReceived('error')->with(
            'erp.request.inertia_5xx',
            Mockery::on(fn (array $data): bool => ($data['error_id'] ?? '') === 'p0-http-503-test'
                && ($data['status'] ?? null) === 503
                && is_string($data['exception_file'] ?? null)
                && is_int($data['exception_line'] ?? null)
                && ! array_key_exists('exception_message', $data))
        )->once();
    }

    public function test_inertia_403_404_and_success_do_not_log_inertia_5xx(): void
    {
        config()->set('app.debug', false);
        Log::spy();
        Route::get('/testing/p0-forbidden', fn () => abort(403));
        Route::get('/testing/p0-not-found', fn () => abort(404));
        Route::get('/testing/p0-success', fn () => response()->json(['ok' => true]));

        $headers = [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ];
        $this->withHeaders($headers)->get('/testing/p0-forbidden')->assertForbidden();
        $this->withHeaders($headers)->get('/testing/p0-not-found')->assertNotFound();
        $this->withHeaders($headers)->get('/testing/p0-success')->assertOk();

        Log::shouldNotHaveReceived('error', ['erp.request.inertia_5xx']);
    }

    public function test_log_failure_cannot_break_safe_inertia_error_page(): void
    {
        config()->set('app.debug', false);
        Log::spy();
        Log::shouldReceive('error')
            ->with('erp.request.inertia_5xx', Mockery::type('array'))
            ->once()
            ->andThrow(new \RuntimeException('logging unavailable'));
        Route::get('/testing/p0-log-down', fn () => throw new \RuntimeException('secret exception details'));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'p0-logging-unavailable',
        ])->get('/testing/p0-log-down')
            ->assertStatus(500)
            ->assertJsonPath('props.errorId', 'p0-logging-unavailable')
            ->assertJsonMissing(['message' => 'secret exception details']);
    }
}
