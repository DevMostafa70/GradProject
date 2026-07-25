<?php

namespace App\Http\Controllers\API\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanyJob;
use App\Services\CompanyInterview\CandidateInvitationService;
use App\Services\CompanyInterview\CandidateSpreadsheetService;
use App\Support\ResolvesAuthenticatedCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CompanyCandidateImportController extends Controller
{
    use ResolvesAuthenticatedCompany;

    public function __construct(
        private readonly CandidateSpreadsheetService $spreadsheetService,
        private readonly CandidateInvitationService $invitationService,
    ) {
    }

    public function preview(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        $validated = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->spreadsheetService->preview($job, $validated['excel_file']),
        ]);
    }

    public function confirm(Request $request, CompanyJob $job): JsonResponse
    {
        $this->authorizeJob($job);

        $validated = $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $preview = $this->spreadsheetService->preview($job, $validated['excel_file']);
        $imported = [];
        $failed = [];

        foreach ($preview['rows'] as $row) {
            if (!$row['valid']) {
                continue;
            }

            try {
                $invitation = $this->invitationService->createAndDispatch($job, $row);

                $imported[] = [
                    'row_number' => $row['row_number'],
                    'candidate_id' => $invitation->candidate_id,
                    'invitation_id' => $invitation->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'expires_at' => $invitation->expires_at?->toISOString(),
                ];
            } catch (Throwable $exception) {
                $failed[] = [
                    'row_number' => $row['row_number'],
                    'email' => $row['email'],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Candidate import completed. Valid invitations were queued for delivery.',
            'data' => [
                'preview_summary' => $preview['summary'],
                'imported_count' => count($imported),
                'failed_during_import_count' => count($failed),
                'imported' => $imported,
                'failed_during_import' => $failed,
                'invalid_rows' => array_values(array_filter(
                    $preview['rows'],
                    fn (array $row): bool => !$row['valid']
                )),
            ],
        ], count($imported) > 0 ? 201 : 422);
    }

    private function authorizeJob(CompanyJob $job): void
    {
        abort_unless($job->company_id === $this->authenticatedCompany()->id, 403, 'Unauthorized.');
    }
}
