<?php

namespace App\Modules\Traffic\Experience\Controllers;

use App\Modules\Traffic\Application\BossDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class BossDashboardController
{
    public function __construct(private readonly BossDashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $dateFrom = $this->date($request->query('date_from'), $today->subDays(29));
        $dateTo = $this->date($request->query('date_to'), $today);
        abort_if($dateFrom->greaterThan($dateTo), 422, 'date_from must be on or before date_to.');
        abort_if($dateFrom->diffInDays($dateTo) > 3660, 422, 'Date range must not exceed 3660 days.');

        $routeProject = $request->route('projectKey');
        $projectKey = trim((string) ($routeProject ?: $request->query('project', 'yby-irrigation')));

        try {
            $dimension = trim((string) $request->query('dimension', ''));
            $value = trim((string) $request->query('value', ''));
            $drilldown = null;
            if ($dimension !== '') {
                abort_unless(in_array($dimension, ['campaign', 'landing', 'source'], true), 422, 'Unsupported drilldown dimension.');
                abort_if($value === '', 422, 'Drilldown value is required.');
                abort_if(in_array($dimension, ['campaign', 'landing'], true) && (! ctype_digit($value) || (int) $value < 1), 422, 'Drilldown id must be a positive integer.');
                $drilldown = ['dimension' => $dimension, 'value' => $value];
            }
            $compareMode = trim((string) $request->query('compare_mode', 'previous'));
            abort_unless(in_array($compareMode, ['none', 'previous', 'yoy', 'custom'], true), 422, 'Unsupported comparison mode.');

            $granularity = trim((string) $request->query('granularity', 'auto'));
            abort_unless(in_array($granularity, ['auto', 'day', 'week', 'month', 'year'], true), 422, 'Unsupported comparison granularity.');

            $compareFrom = null;
            $compareTo = null;
            if ($compareMode === 'custom') {
                $compareFrom = $this->date($request->query('compare_from'), $dateFrom->subDays($dateFrom->diffInDays($dateTo) + 1));
                $compareTo = $this->date($request->query('compare_to'), $dateFrom->subDay());
                abort_if($compareFrom->greaterThan($compareTo), 422, 'compare_from must be on or before compare_to.');
                abort_if($compareFrom->diffInDays($compareTo) > 3660, 422, 'Comparison range must not exceed 3660 days.');
            }

            $payload = $this->dashboard->build(
                $projectKey,
                $dateFrom->toDateString(),
                $dateTo->toDateString(),
                $drilldown,
                [
                    'compare_mode' => $compareMode,
                    'granularity' => $granularity,
                    'compare_from' => $compareFrom?->toDateString(),
                    'compare_to' => $compareTo?->toDateString(),
                ],
            );
        } catch (InvalidArgumentException) {
            abort(404, 'Business project not found.');
        }

        return Inertia::render('Traffic/BossDashboard', $payload);
    }

    private function date(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        abort_if($parsed === false || $parsed->toDateString() !== trim($value), 422, 'Dates must use YYYY-MM-DD.');

        return $parsed;
    }
}
