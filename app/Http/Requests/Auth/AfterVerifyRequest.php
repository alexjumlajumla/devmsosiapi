<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use App\Models\User;

class AfterVerifyRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     * @return array
     */
    // public function rules(): array
	// {
	// 	return [
    //         'password'  => 'string',
    //         'email'     => [
    //             'email',
    //             Rule::unique('users', 'email')->whereNull('email_verified_at')
    //         ],
    //         'firstname' => 'string|min:2|max:100',
    //         'referral'  => 'string|exists:users,my_referral|max:255',
    //         'gender'    => 'in:male,female',
	// 	];
	// }

    public function rules(): array
    {
        return [
            'password'  => 'string',
            'email'     => [
                'email',
                function ($attribute, $value, $fail) {
                    $user = User::where('email', $value)->first();
                    
                    // If user doesn't exist, apply standard validation
                    if (!$user) {
                        // Check if another user already has this email
                        $otherUser = User::where('email', $value)->whereNotNull('email_verified_at')->exists();
                        if ($otherUser) {
                            $fail('This email is already taken.');
                        }
                        return;
                    }
                    
                    // If user has verified email or has a phone number, allow the email
                    if ($user->email_verified_at !== null || !empty($user->phone)) {
                        return;
                    }
                    
                    // Otherwise, fail validation
                    $fail('The email must be verified or a phone number must be provided.');
                },
            ],
            'firstname' => 'string|min:2|max:100',
            'referral'  => 'string|exists:users,my_referral|max:255',
            'gender'    => 'in:male,female',
        ];
    }


    
}
