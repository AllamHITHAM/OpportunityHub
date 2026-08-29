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
    public function __construct(private readonly NotificationService $notifications)
    {
    }

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

                // Phase 7A-2: only reached after both eligibility checks
                // above pass and the Offer/status mutation succeeds -- no
                // notification for a duplicate Offer, an ineligible source
                // status, or an incomplete Assessment. Phase 7A-4.1: the
                // freshly-created $offer is passed through so
                // NotificationService can forward its display fields to
                // EmailService for the "Offer Received" email -- this is
                // still exactly one call to notifyOfferSent(), not a second
                // call to EmailService from here (see NotificationService's
                // own doc comment on that boundary).
                $this->notifications->notifyOfferSent(
                    $application->studentProfile->user,
                    $application->opportunity->title,
                    $application->id,
                    $offer,
                );

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

            // Phase 7A-2: only reached once per Offer -- a second response
            // (or a decline racing an accept) always finds `status` no
            // longer `sent` and throws above instead, so this never fires
            // twice for the same Offer. Organization-facing only; the
            // student who just responded is never notified about their own
            // action.
            $organizationUser = $application->opportunity->organizationProfile->user;
            $studentName = $application->studentProfile->user->name;
            $opportunityTitle = $application->opportunity->title;

            if ($offerStatus === 'accepted') {
                $this->notifications->notifyOfferAccepted(
                    $organizationUser,
                    $studentName,
                    $opportunityTitle,
                    $application->id,
                );
            } else {
                // The only other terminal value `respondToOffer()` is ever
                // called with is `declined` (see `declineOffer()` above) --
                // never a generic "else" for some other unrelated status.
                $this->notifications->notifyOfferDeclined(
                    $organizationUser,
                    $studentName,
                    $opportunityTitle,
                    $application->id,
                );
            }

            return $locked;
        });
    }

    /**
     * **Phase 10A.3**: `$application->assessment` is `Application`'s own
     * *current/latest* Assessment accessor (`hasOne(...)->latestOfMany()`,
     * not a plain `hasOne` -- see that model's doc comment), so this
     * unchanged one-line check already implements the correct multi-
     * assessment-history eligibility rule without needing to enumerate
     * `assessments()` itself: a completed Quiz with no follow-up Interview
     * → its own row is the latest → eligible. A completed Quiz advanced to
     * a new Interview that's still `scheduled`/`in_progress` → the
     * Interview is now the latest, and it isn't `completed` → blocked,
     * exactly as required (an old completed Quiz can never satisfy Offer
     * eligibility once a newer Assessment exists and hasn't finished).
     * Interview completed (whether or not a Quiz preceded it) → the
     * Interview is the latest and is `completed` → eligible. `result` is
     * still never inspected here, on any Assessment in the chain -- see
     * this method's own long-standing rule below.
     */
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
