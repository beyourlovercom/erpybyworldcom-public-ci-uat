<?php

namespace App\Modules\Traffic\Experience\Controllers;

use App\Modules\Traffic\Application\Ga4TrafficDetailService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class Ga4TrafficDetailController
{
    public function __construct(private readonly Ga4TrafficDetailService $detail) {}

    public function __invoke(Request $request, string $projectKey): Response
    {
        $today = CarbonImmutable::today();
        $dateFrom = $this->date($request->query('date_from'), $today->subDays(29));
        $dateTo = $this->date($request->query('date_to'), $today);

        abort_if($dateFrom->greaterThan($dateTo), 422, 'date_from must be on or before date_to.');
        abort_if($dateFrom->diffInDays($dateTo) > 366, 422, 'Date range must not exceed 366 days.');

        try {
            $payload = $this->detail->build($projectKey, $dateFrom->toDateString(), $dateTo->toDateString());
        } catch (InvalidArgumentException) {
            abort(404, 'Business project not found.');
        }

        return Inertia::render('Traffic/Ga4/Index', $payload);
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
