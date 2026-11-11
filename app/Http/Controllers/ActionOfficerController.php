<?php

namespace App\Http\Controllers;

use App\Models\ActionOfficer;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ImisDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionOfficerController extends Controller
{
    /** The IMIS employees to choose an officer from, "Last, First M." in last-name order. Empty when IMIS cannot be reached. */
    public function directory(Request $request): JsonResponse
    {
        abort_unless($request->user()->isPsed(), 403);

        return response()->json(['employees' => collect(ImisDirectory::employees())->map(fn ($e) => [
            'value' => $e['label'],
            'label' => $e['label'],
            'id' => $e['id'],
            'position' => $e['position'],
        ])->all()]);
    }

    /**
     * PSED staff say who a set of initials is: pick the IMIS employee, or type a name for someone IMIS
     * no longer lists. The name of a picked employee comes from IMIS, not from the browser.
     */
    public function update(Request $request, ActionOfficer $officer): JsonResponse
    {
        abort_unless($request->user()->isPsed(), 403);

        $data = $request->validate([
            'imis_user_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'min:3', 'max:120', 'required_without:imis_user_id'],
        ], ['name.required_without' => 'Choose the employee, or type the full name.']);

        $before = ['name' => $officer->name, 'imis_user_id' => $officer->imis_user_id];

        if (isset($data['imis_user_id'])) {
            $employee = ImisDirectory::find($data['imis_user_id']);
            abort_if($employee === null, 422, 'That employee was not found in IMIS.');

            $officer->name = $employee['name'];
            $officer->imis_user_id = $employee['id'];
            $officer->user_id = User::where('imis_user_id', $employee['id'])->value('id');
        } else {
            $officer->name = trim(preg_replace('/\s+/', ' ', $data['name']));
        }

        $officer->save();
        AuditLog::record('action_officer.named', $officer, before: $before, after: ['initials' => $officer->initials, 'name' => $officer->name, 'imis_user_id' => $officer->imis_user_id]);

        return response()->json(['initials' => $officer->initials, 'name' => $officer->name]);
    }
}
