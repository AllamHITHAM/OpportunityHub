<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewRequest extends FormRequest
{
    use InteractsWithInterviewRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->interviewCreationRules();
    }
}
