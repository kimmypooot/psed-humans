<?php

namespace App\Http\Controllers;

use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Support\ImisDirectory;
use App\Support\Transmittal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The transmittal letter, before it is sent: what the form starts with, and a draft to read.
 * The Action Officer generates the letter after the Chief approves or marks it For Compliance
 * (MechanismWorkflowController::generateTransmittal); only they may see its draft. The Chief may list the
 * documents the officer forwarded (`documents=1`) while deciding.
 */
class TransmittalController extends Controller
{
    /** The form's starting values and the two people who may sign. */
    public function show(Request $request, HrMechanism $mechanism, string $type): JsonResponse
    {
        $documentsOnly = $request->boolean('documents');
        $this->authorizeFor($mechanism, $type, $documentsOnly);

        $documents = collect(MechanismPresenter::attachments($mechanism, $request->user()))->whereIn('kind', HrMechanismAttachment::UPLOADED)->values()->all();

        if ($documentsOnly) {
            return response()->json(['documents' => $documents]);
        }

        $signatories = collect(ImisDirectory::signatories());
        // The letter in the template is signed by the Director III; the Director IV is the other choice.
        $default = $signatories->first(fn ($s) => $s['position'] === 'Director III') ?? $signatories->first();

        return response()->json([
            'defaults' => Transmittal::defaults($mechanism->loadMissing('agency')) + ['signatory_id' => $default ? (string) $default['id'] : ''],
            'documents' => $documents,
            'signatories' => $signatories->map(fn ($s) => ['value' => (string) $s['id'], 'label' => $s['label'].' — '.$s['position']])->values()->all(),
        ]);
    }

    /**
     * The letter as the form stands, as the words of each paragraph, for the preview on screen. It comes from the
     * same code that writes the Word file, so the preview says exactly what the letter will say.
     */
    public function view(Request $request, HrMechanism $mechanism, string $type): JsonResponse
    {
        $this->authorizeFor($mechanism, $type);

        $fields = $request->validate(Transmittal::rules());

        return response()->json([
            'type' => $type,
            'slots' => Transmittal::slots($mechanism->loadMissing('agency'), $type, self::letter($fields)),
        ]);
    }

    /** A draft of the letter as the form stands, to read before sending. Nothing is saved or sent. */
    public function preview(Request $request, HrMechanism $mechanism, string $type): StreamedResponse
    {
        $this->authorizeFor($mechanism, $type);

        $fields = $request->validate(Transmittal::rules());
        $bytes = Transmittal::render($mechanism->loadMissing('agency'), $type, self::letter($fields));

        return response()->streamDownload(fn () => print ($bytes), 'DRAFT_'.Transmittal::fileName($mechanism, $type), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * The form's fields with the signatory's name and position from IMIS, by the id picked.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function letter(array $fields): array
    {
        $signatory = ImisDirectory::signatory((int) $fields['signatory_id']);

        if ($signatory === null) {
            throw ValidationException::withMessages(['transmittal.signatory_id' => 'Choose the Director IV or the Director III who signs. If the list is empty, IMIS could not be reached; try again in a moment.']);
        }

        return $fields + ['signatory_name' => $signatory['printed'], 'signatory_position' => $signatory['position']];
    }

    /** The Action Officer drafts the letter the Chief's decision calls for. The Chief may only list the documents. */
    private function authorizeFor(HrMechanism $mechanism, string $type, bool $documentsOnly = false): void
    {
        abort_unless(isset(Transmittal::TYPES[$type]), 404);

        if ($documentsOnly) {
            $this->authorize($type === 'approval' ? 'review' : 'returnForCompliance', $mechanism);

            return;
        }

        $this->authorize('generateTransmittal', $mechanism);
        abort_unless(Transmittal::typeFor($mechanism) === $type, 404);
    }
}
