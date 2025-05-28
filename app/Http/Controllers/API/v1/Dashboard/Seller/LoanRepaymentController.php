<?php

namespace App\Http\Controllers\API\v1\Dashboard\Seller;

use App\Helpers\ResponseError;
use App\Http\Resources\LoanRepaymentResource;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Services\LoanService\LoanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanRepaymentController extends SellerBaseController
{
    public function __construct(private LoanService $service)
    {
        parent::__construct();
    }

    /**
     * List repayments for loans owned by the authenticated seller.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $repayments = LoanRepayment::with('loan')
            ->whereHas('loan', fn ($q) => $q->where('user_id', auth('sanctum')->id()))
            ->orderBy($request->input('column', 'id'), $request->input('sort', 'desc'))
            ->paginate($request->input('perPage', 15));

        return LoanRepaymentResource::collection($repayments);
    }

    /**
     * Store a new repayment for the seller's own loan.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'loan_id'        => 'required|exists:loans,id',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:wallet,mobile_money,card,selcom',
        ]);

        $loan = Loan::find($data['loan_id']);
        abort_if(!$loan || $loan->user_id !== auth('sanctum')->id(), 403);

        // Disallow cash from seller repayment
        if ($data['payment_method'] === 'cash') {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => 'Cash payments are not allowed for seller repayments.'
            ]);
        }

        $result = $this->service->recordRepayment($data);

        if (isset($result['redirect_url'])) {
            return $this->successResponse(__('web.go_to_payment'), ['redirect_url' => $result['redirect_url']]);
        }

        return $result['status']
            ? $this->successResponse(__('web.record_was_successfully_created'), $result['data'])
            : $this->onErrorResponse($result);
    }
} 