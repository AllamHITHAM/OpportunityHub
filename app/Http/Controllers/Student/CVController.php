<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreCVRequest;
use App\Models\CV;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CVController extends Controller
{
    /**
     * The disk CV files are stored on -- `local`'s root is
     * `storage/app/private` (see config/filesystems.php), never the public
     * web root, so a file is only ever reachable through an authenticated,
     * ownership-checked controller action (this class / the Organization
     * CV download action), never a direct/guessable URL.
     */
    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $cvs = $request->user()->studentProfile->cvs;

        return response()->json([
            'success' => true,
            'message' => 'CVs retrieved successfully',
            'data' => $cvs,
        ]);
    }

    /**
     * Phase 8A-4: real multipart PDF upload. The client never chooses the
     * stored path/filename -- a fresh, random server filename is generated
     * (never the original upload's filename), so nothing about the request
     * can influence where or under what name the file lands on disk.
     */
    public function store(StoreCVRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        $filename = Str::uuid()->toString().'.pdf';

        $storedPath = $request->file('file')->storeAs(
            "cvs/{$studentProfile->id}",
            $filename,
            self::DISK,
        );

        abort_unless($storedPath !== false, 500, 'Failed to store the CV file.');

        $cv = $studentProfile->cvs()->create([
            'title' => $request->validated('title'),
            'file_path' => $storedPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'CV created successfully',
            'data' => $cv,
        ], 201);
    }

    /**
     * Streams the CV's own PDF back to the student it belongs to. Serves
     * inline (not a forced download) so a browser can render it directly --
     * "View CV" in the Flutter app.
     *
     * A pre-Phase-8A-4 row may still carry a legacy, non-managed path
     * (e.g. a local Windows path typed into the old `file_path` text
     * field) -- `Storage::exists()` against that string on the `local`
     * disk will simply be false (it was never really stored here), which
     * this deliberately treats as a normal, controlled 404 rather than
     * attempting to read an arbitrary path.
     */
    public function download(CV $cv, Request $request): JsonResponse|StreamedResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        if (! Storage::disk(self::DISK)->exists($cv->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'CV file not found',
                'data' => null,
            ], 404);
        }

        return Storage::disk(self::DISK)->response($cv->file_path, $cv->title.'.pdf');
    }

    public function destroy(CV $cv, Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        if ($cv->student_id !== $studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        if ($cv->applications()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a CV that has been used in an application',
                'data' => null,
            ], 409);
        }

        $cv->delete();

        // Only ever deletes a path this application itself generated and
        // scoped under this CV's own owning student (see store() above) --
        // a legacy/manually-typed `file_path` never matches this prefix,
        // so it's left alone rather than risking an arbitrary filesystem
        // delete on a client-influenced (pre-Phase-8A-4) string.
        if (str_starts_with($cv->file_path, "cvs/{$studentProfile->id}/")) {
            Storage::disk(self::DISK)->delete($cv->file_path);
        }

        return response()->json([
            'success' => true,
            'message' => 'CV deleted successfully',
            'data' => null,
        ]);
    }

    public function setDefault(CV $cv, Request $request): JsonResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        DB::transaction(function () use ($cv) {
            $cv->studentProfile->cvs()->where('id', '!=', $cv->id)->update(['is_default' => false]);
            $cv->update(['is_default' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default CV updated successfully',
            'data' => $cv->fresh(),
        ]);
    }
}
