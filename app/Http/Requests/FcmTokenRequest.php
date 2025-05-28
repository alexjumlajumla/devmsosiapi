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
        return true;
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
                'min:152',
                'max:200',
                'regex:/^[a-zA-Z0-9\-_.~%]+$/',
                function ($attribute, $value, $fail) {
                    // Additional validation for FCM token format
                    if (!preg_match('/^[a-zA-Z0-9\-_.~%]+$/', $value)) {
                        $fail('The ' . $attribute . ' contains invalid characters.');
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
