<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\InteractsWithQuestionRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionRequest extends FormRequest
{
    use InteractsWithQuestionRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->questionRules();
    }
}
