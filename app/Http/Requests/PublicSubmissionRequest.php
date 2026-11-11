<?php

namespace App\Http\Requests;

use App\Enums\MechanismType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** What an agency fills in on the public page. */
class PublicSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Only the agencies on the psed-app list are offered, so only those are accepted.
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('listed', true)],
            'mechanism_type' => ['required', Rule::enum(MechanismType::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'document' => ['required', 'file', 'extensions:docx', 'mimes:docx', 'max:'.config('hrmm.max_upload_kb')],
            // A hidden field people never see: only a script fills it in.
            'website' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'agency_id.required' => 'Choose your agency.',
            'agency_id.exists' => 'Choose your agency from the list.',
            'mechanism_type.required' => 'Choose the kind of HR mechanism.',
            'contact_email.required' => 'Enter an email address so we can send you updates.',
            'document.required' => 'Attach the file of the HR mechanism.',
            'document.mimes' => 'The file must be a Word (.docx) file.',
            'document.extensions' => 'The file must be a Word (.docx) file.',
            'document.max' => 'The file is too large. The most we accept is '.floor(config('hrmm.max_upload_kb') / 1024).' MB.',
        ];
    }
}
