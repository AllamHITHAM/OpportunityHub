<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Messaging MVP -- shared between the Organization and Student sides of
 * `ConversationController::storeMessage()` (one Conversation resource,
 * see that controller's own doc comment). `body` is the only field a
 * client can ever set; `sender_user_id`/`conversation_id` always come
 * from the authenticated session and the route parameter, never the
 * request body.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * A whitespace-only body (e.g. `"   "`) passes `required`/`string`
     * but is not a real message -- rejected the same way an empty one
     * would be, via a custom rule rather than a `min:1` (which only
     * counts characters, not meaningful content).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $body = $this->input('body');
            if (is_string($body) && trim($body) === '') {
                $validator->errors()->add('body', 'The body field must not be blank.');
            }
        });
    }
}
