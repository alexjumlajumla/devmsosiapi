<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseError;
use App\Models\User;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;

class CheckSellerShop
{
    use ApiResponse;

    public function handle(Request $request, Closure $next)
    {
        if (!auth('sanctum')->check()) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_100,
                'message'   => __('errors.' . ResponseError::ERROR_100, locale: request('language', 'en'))
            ]);
        }

        /** @var User $user */
        $user = auth('sanctum')->user();

        if ($user?->shop && $user?->hasRole(['seller', 'admin'])) {
            return $next($request);
        }

        if ($user?->moderatorShop && $user?->role == 'moderator' || $user?->role == 'deliveryman') {
            return $next($request);
        }

        return $this->onErrorResponse([
            'code'      => ResponseError::ERROR_204,
            'message'   => __('errors.' . ResponseError::ERROR_204, locale: request('language', 'en'))
        ]);
    }
}
