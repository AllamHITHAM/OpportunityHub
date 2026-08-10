<?php

namespace App\Services;

use App\Exceptions\InvalidOfferSourceStatusException;
use App\Exceptions\OfferAlreadyExistsException;
use App\Exceptions\OfferAlreadyRespondedException;
use App\Models\Application;
use App\Models\Offer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the multi-model Offer workflow (Phase 6C-1): sending a final Offer
 * for an `in_assessment` application with a completed Assessment, and the
 * student's terminal accept/decline response to it. Every
 * `Application.status` transition this workflow produces
 * (`offer_sent` / `accepted` / `rejected`) is written from exactly one
 * place here, never by a controller directly -- the same doctrine
 * `AssessmentService::transitionToInAssessment()` already established for
 * `in_assessment`.
 *
 * Deliberately does not perform HTTP response construction, does not
 * return a JsonResponse, and does not perform organization/student
 * ownership authorization -- all of that stays in the calling controllers,
 * matching every other controller/service pairing in this codebase (see
 * `AssessmentService`).
 */
class OfferService
{
    /**
     * Creates an Offer for `$application` and moves it to `offer_sent`, in
     * one transaction.
     *
     * `Assessment.result` is deliberately never inspected here -- a
     * `failed`/`waiting` result does not block sending an Offer. The
     * organization retains final hiring authority; the assessment result is
     * informational only (see docs/BUSINESS_RULES.md section 5).
     *
     * @param  array<string, mixed>  $offerData  Already-validated Offer terms
     *                                            (title, salary_*, start_date,
     *                                            message) -- never
     *                                            status/sent_at/responded_at;
     *                                            those are hardcoded below,
     *                                            never accepted from the caller.
     *
     * @throws InvalidOfferSourceStatusException  When the application isn't
     *                                             `in_assessment`, has no
     *                                             Assessment, or that
     *                                             Assessment isn't `completed`.
     * @throws OfferAlreadyExistsException         When the application already
     *                                              has an Offer (pre-check or a
     *                                              translated unique-constraint
     *                                              violation).
     */
    public function sendOffer(Application $application, array $offerData): Offer
    {
        // Existing-offer is checked first so that a genuine duplicate
        // always reports as "already exists" (409), even for an
        // application whose status has since moved on (whose status alone
        // would otherwise -- and redundantly -- also fail the source-status
        // check below).
        $this->assertNoExistingOffer($application);
        $this->assertEligibleForOffer($application);

        try {
            return DB::transaction(function () use ($application, $offerData) {
                $offer = $application->offer()->create([
                    ...$offerData,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $application->status = 'offer_sent';
                $application->save();

                return $offer;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateOfferViolation($e)) {
                throw new OfferAlreadyExistsException();
            }

            throw $e;
        }
    }

    /**
     * The student accepts `$offer`. See `respondToOffer()` for the shared
     * locking/transition mechanics.
     *
     * @throws OfferAlreadyRespondedException  When the offer's `status` is
     *                                          no longer `sent` by the time
     *                                          the row lock is acquired.
     */
    public function acceptOffer(Offer $offer): Offer
    {
        return $this->respondToOffer($offer, offerStatus: 'accepted', applicationStatus: 'accepted');
    }

    /**
     * The student declines `$offer`. Both a declined Offer and an
     * early-funnel organization rejection converge on the same
     * `Application.status = rejected` (see docs/BUSINESS_RULES.md section
     * 11) -- only the actor and the Offer's own `status` distinguish which
     * happened. See `respondToOffer()` for the shared locking/transition
     * mechanics.
     *
     * @throws OfferAlreadyRespondedException  When the offer's `status` is
     *                                          no longer `sent` by the time
     *                                          the row lock is acquired.
     */
    public function declineOffer(Offer $offer): Offer
    {
        return $this->respondToOffer($offer, offerStatus: 'declined', applicationStatus: 'rejected');
    }

    /**
     * Shared accept/decline transition. Locks the Offer row for the
     * duration of the transaction (the same `lockForUpdate()` discipline
     * `Student\QuizController::submit()` already uses for its own
     * terminal, once-only transition) so a genuine concurrent double-response
     * -- accept and decline racing each other, or two of the same request --
     * is rejected the same way a simple repeat request is: whichever
     * transaction commits first wins, and the second re-reads `status`
     * as no longer `sent` and throws, rather than silently overwriting the
     * first response. `Offer.status` and `Application.status` are updated
     * inside that same transaction, so the two can never diverge into an
     * impossible combination (e.g. Offer accepted + Application rejected).
     */
    private function respondToOffer(Offer $offer, string $offerStatus, string $applicationStatus): Offer
    {
        return DB::transaction(function () use ($offer, $offerStatus, $applicationStatus) {
            $locked = Offer::where('id', $offer->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'sent') {
                throw new OfferAlreadyRespondedException();
            }

            $locked->status = $offerStatus;
            $locked->responded_at = now();
            $locked->save();

            $application = $locked->application;
            $application->status = $applicationStatus;
            $application->save();

            return $locked;
        });
    }

    private function assertEligibleForOffer(Application $application): void
    {
        if ($application->status !== 'in_assessment') {
            throw new InvalidOfferSourceStatusException();
        }

        $assessment = $application->assessment()->first();

        if ($assessment === null || $assessment->status !== 'completed') {
            throw new InvalidOfferSourceStatusException(
                'An offer can only be sent once the assessment is completed.'
            );
        }
    }

    private function assertNoExistingOffer(Application $application): void
    {
        if ($application->offer()->exists()) {
            throw new OfferAlreadyExistsException();
        }
    }

    /**
     * Narrowly confirms a QueryException is the
     * `offers_application_id_unique` violation -- the concurrency-race
     * counterpart to the pre-check in `sendOffer()` -- before treating it
     * as "someone already sent an offer for this application". Any other
     * integrity-constraint failure is rethrown untouched so it surfaces as
     * a genuine 500, never silently mislabeled as "duplicate".
     */
    private function isDuplicateOfferViolation(QueryException $e): bool
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'offers_application_id_unique')
            || str_contains($message, 'offers.application_id');
    }
}
