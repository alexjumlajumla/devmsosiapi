<?php
declare(strict_types=1);

namespace App\Http\Requests\Combo;

use App\Http\Requests\BaseRequest;

class StoreRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'active'	 	=> 'bool|in:0,1',
			'expired_at' 	=> 'date_format:Y-m-d',
			'images'  	 	=> 'array',
			'images.*'	 	=> 'string',
			'title'        	=> 'required|array',
			'title.*'      	=> 'required|string|min:2|max:191',
			'description'  	=> 'array',
			'description.*'	=> 'string|min:2',
			'stocks'  		=> 'array',
			'stocks.*'		=> 'int|exists:stocks,id',
        ];
    }
}
