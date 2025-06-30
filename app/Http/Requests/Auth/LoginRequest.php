<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class LoginRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     * @return array
     */
    public function rules(): array
	{
		return [
            'phone'     => ['nullable', 'numeric'],
            'password'  => ['required', 'string'],
            'email'     => ['nullable', 'email'],
            'firebase_token' => ['nullable', 'string'],
		];
	}
}
