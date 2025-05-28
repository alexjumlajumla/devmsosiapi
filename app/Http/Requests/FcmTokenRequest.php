<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FcmTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true; // Handled by auth middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'token' => [
                'required',
                'string',
                'min:100',
                'max:500',
                // Basic FCM token format validation
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $value)) {
                        $fail('The FCM token format is invalid.');
                    }
                },
            ],
        ];
    }
    
    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'token.required' => 'The FCM token is required.',
            'token.string' => 'The FCM token must be a string.',
            'token.min' => 'The FCM token must be at least :min characters.',
            'token.max' => 'The FCM token may not be greater than :max characters.',
        ];
    }
}
