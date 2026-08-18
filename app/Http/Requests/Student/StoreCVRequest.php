<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreCVRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Phase 8A-4: real multipart PDF upload replaces the old client-supplied
     * `file_path` string -- a client can no longer choose where its "CV"
     * points on the server, only supply the actual file. `mimes:pdf`
     * inspects real file content, not just the extension.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
