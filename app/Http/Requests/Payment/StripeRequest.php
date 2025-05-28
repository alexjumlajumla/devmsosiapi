<?php

namespace App\Http\Requests\Payment;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Order\StoreRequest as OrderStoreRequest;
use Illuminate\Validation\Rule;

class StripeRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
		$rules = [];

		if (request('cart_id')) {
			$rules = (new OrderStoreRequest)->rules();
		}

		return array_merge([
			'cart_id'  => [
				Rule::exists('carts', 'id')
			],
			'order_id'  => [
				Rule::exists('orders', 'id')
			],
			'parcel_id' => [
				Rule::exists('parcel_orders', 'id')
			],
			'wallet_id' => [
			 	Rule::exists('wallets', 'id')->where('user_id', auth('sanctum')->id())
			],
			'total_price' => [
				'numeric'
			],
			'tips' => [
				'numeric'
			],
			'after_payment_tips' => [
				'numeric'
			],
		], $rules);
    }

}
