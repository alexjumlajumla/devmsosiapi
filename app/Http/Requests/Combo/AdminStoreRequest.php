<?php
declare(strict_types=1);

namespace App\Http\Requests\Combo;

use App\Http\Requests\BaseRequest;

class AdminStoreRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'shop_id' => 'required|int|exists:shops,id',
        ] + (new StoreRequest)->rules();
    }
}
