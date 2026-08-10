<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\OfferAlreadyRespondedException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Offer;
use App\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-side Offer response (Phase 6C-1): viewing the one Offer (if any)
 * on one of the student's own applications, and responding to it exactly
 * once with accept or decline. No re-response/undo exists -- see
 * `OfferService::respondToOffer()`.
 */
class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers)
    {
    }

    /**
     * Deliberately returns the same 404 for "this application isn't
     * yours" and "no offer exists yet", never revealing which case
     * applies -- the same "prefer not revealing" posture
     * `Student\QuizController::show()` already takes for its own
     * not-found case.
     */
    public function show(Application $application, Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        if ($application->student_id !== $studentId || $application->offer === null) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer retrieved successfully',
            'data' => $application->offer,
        ]);
    }

    public function accept(Offer $offer, Request $request): JsonResponse
    {
        if ($offer->application->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not found',
                'data' => null,
            ], 404);
        }

        try {
            $accepted = $this->offers->acceptOffer($offer);
        } catch (OfferAlreadyRespondedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer accepted successfully',
            'data' => $accepted,
        ]);
    }

    public function decline(Offer $offer, Request $request): JsonResponse
    {
        if ($offer->application->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not found',
                'data' => null,
            ], 404);
        }

        try {
            $declined = $this->offers->declineOffer($offer);
        } catch (OfferAlreadyRespondedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Offer declined successfully',
            'data' => $declined,
        ]);
    }
}
