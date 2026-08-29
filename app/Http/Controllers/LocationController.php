<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\LocationCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The canonical Location Catalog (Phase O8.2) -- role-agnostic, like
 * `GET /api/notifications`: both a Student (available work locations) and
 * an Organization (an Opportunity's location) pick from this exact same
 * list of `{id, canonical_name, alias_names}` rows, so there is one shared
 * endpoint rather than a near-duplicate per role.
 */
class LocationController extends Controller
{
    public function __construct(private readonly LocationCatalogService $catalog)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Location catalog retrieved successfully',
            'data' => Location::with('aliases:id,location_id,alias')
                ->orderBy('canonical_name')
                ->get(['id', 'canonical_name']),
        ]);
    }

    /**
     * Resolves a typed location name to one canonical [Location]
     * (Recommendation Accuracy Patch) -- reusing an existing canonical
     * name or known alias whenever one matches, and only ever creating a
     * brand new canonical row when nothing at all does. Never stores raw
     * free text anywhere else in the app: this is the one safe, backend-
     * normalized path a client's "Add '<typed text>'" affordance calls.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $result = $this->catalog->findOrCreate($validated['name']);
        $result['location']->load('aliases:id,location_id,alias');

        return response()->json([
            'success' => true,
            'message' => $result['created']
                ? 'Location created successfully'
                : 'Matched an existing location',
            'data' => $result['location'],
        ], $result['created'] ? 201 : 200);
    }
}
