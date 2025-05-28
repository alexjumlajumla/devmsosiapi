<?php

namespace App\Http\Controllers\API\v1\Dashboard\Seller;

use App\Http\Controllers\API\v1\Dashboard\Seller\SellerBaseController;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanController extends SellerBaseController
{
    /**
     * Display a listing of the seller's loans.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $loans = Loan::with('repayments')
            ->where('user_id', auth('sanctum')->id())
            ->orderBy($request->input('column', 'id'), $request->input('sort', 'desc'))
            ->paginate($request->input('perPage', 15));

        return LoanResource::collection($loans);
    }

    /**
     * Show a single loan owned by the seller.
     */
    public function show(Loan $loan)
    {
        abort_if($loan->user_id !== auth('sanctum')->id(), 403);

        return $this->successResponse(__('web.record_has_been_successfully_found'), LoanResource::make($loan->load('repayments')));
    }
} 