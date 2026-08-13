<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarRequest;
use App\Http\Resources\CalendarEntryResource;
use App\Services\Posts\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * HTTP surface for GET /api/calendar (Task 21). Pure orchestration — see
 * CalendarService's docblock for the query-cost reasoning and the
 * tag-filter tenant-safety rule.
 */
class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendar,
    ) {}

    public function index(CalendarRequest $request): JsonResponse
    {
        $groups = $this->calendar->forRange(
            Carbon::parse($request->string('from')->toString()),
            Carbon::parse($request->string('to')->toString()),
            $request->filled('customer') ? $request->string('customer')->toString() : null,
            $request->input('tags', []),
        );

        return response()->json(CalendarEntryResource::collection($groups));
    }
}
