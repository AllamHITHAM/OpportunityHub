<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\InvalidOfferSourceStatusException;
use App\Exceptions\OfferAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\SendOfferRequest;
use App\Models\Application;
use App\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-side Offer authoring (Phase 6C-1): sending the one final
 * Offer an `in_assessment` application with a completed Assessment may
 * receive, and viewing it afterward. No update/delete/cancel/resend
 * action exists -- v1's Offer is immutable once sent (see
 * docs/BUSINESS_RULES.md).
 */
class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers)
    {
    }

    public function store(SendOfferRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        try {
            $offer = $this->offers->sendOffer($application, $request->validated());
        } catch (InvalidOfferSourceStatusException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        } catch (OfferAlreadyExistsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer sent successfully',
            'data' => $offer,
        ], 201);
    }

    /**
     * The one Offer (if any) belonging to an application the organization
     * owns.
     */
    public function show(Application $application, Request $request): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        $offer = $application->offer;

        if ($offer === null) {
            return response()->json([
                'success' => false,
                'message' => 'This application has no offer yet',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer retrieved successfully',
            'data' => $offer,
        ]);
    }
}
