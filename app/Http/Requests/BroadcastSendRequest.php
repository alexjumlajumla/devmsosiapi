<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BroadcastSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        $allowed = ['admin','director','manager','customer_care','product_manager','marketing'];
        return auth('sanctum')->check() && auth('sanctum')->user()->hasAnyRole($allowed);
    }

    public function rules(): array
    {
        return [
            'title'    => 'required|string|max:255',
            'body'     => 'required|string',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:push,email',
            'groups'   => 'required|array|min:1',
            'groups.*' => 'in:admin,seller,deliveryman,user,customer_care,product_manager,director,manager,marketing',
        ];
    }
} 