<?php

namespace App\Http\Controllers\API\v1;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Requests\RestPushNotifyRequest;
use App\Http\Resources\PushNotificationResource;
use App\Repositories\PushNotificationRepository\PushNotificationRepository;
use App\Services\PushNotificationService\PushNotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Traits\Notification;


class PushNotificationController extends Controller
{
    use ApiResponse, Notification {
        // Use onErrorResponse from ApiResponse instead of errorResponse from Notification
        ApiResponse::errorResponse insteadof \App\Traits\Notification;
    }

    public function __construct(
        private PushNotificationRepository $repository,
        private PushNotificationService $service,
        
    )
    {
        parent::__construct();

        $this->middleware(['sanctum.check'])->except(['store', 'restStore']);
        
    }
    

    /**
     * Display a listing of the resource.
     *
     * @param FilterParamsRequest $request
     * @return AnonymousResourceCollection
     */
    public function index(FilterParamsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->merge(['user_id' => auth('sanctum')->id()])->all();
        $model  = $this->repository->paginate($filter);

        return PushNotificationResource::collection($model);
    }

    /**
     * Display a listing of the resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $model = $this->repository->show($id, auth('sanctum')->id());

        return $this->successResponse(
            __('errors.'. ResponseError::SUCCESS, locale: $this->language),
            $model ? PushNotificationResource::make($model) : $model
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return JsonResponse
     */
    public function store(array $data): JsonResponse
    {
        $data['user_id'] = auth('sanctum')->id();

        $model = $this->service->store($data);

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $model ? PushNotificationResource::make($model) : $model
        );

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param RestPushNotifyRequest $request
     * @return JsonResponse
     */
    public function restStore(RestPushNotifyRequest $request): JsonResponse
    {
        $model = $this->service->restStore($request->validated());

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $model ? PushNotificationResource::make($model) : $model
        );

    }

    /**
     * @param int $id
     * @return JsonResponse
     */
    public function readAt(int $id): JsonResponse
    {
        $model = $this->service->readAt($id, auth('sanctum')->id());

        return $this->successResponse(
            __('errors.'. ResponseError::SUCCESS, locale: $this->language),
            $model ? PushNotificationResource::make($model) : $model
        );
    }

    /**
     * @return JsonResponse
     */
    public function readAll(): JsonResponse
    {
        $this->service->readAll(auth('sanctum')->id());

        return $this->successResponse(
            __('errors.'. ResponseError::SUCCESS, locale: $this->language),
        );
    }




    public function testPushNotification1(): JsonResponse
{
    // Example test data for push notification
   // Replace with actual receiver token

	    $receivers = ["eNAjAUGISWGrysLRGImMRp:APA91bEpcvm6jpQDx8UZZf8WdmyHnBX2xddENs532Y8lJFSHQ0SeIvR24mGcT8PDJBFqj1L2hUs3f1-2MCol8USp4C64Dl5ayQNv6ghoC6EQE4jhKiVjywU"];  // Replace with actual receiver token
	
		
		// Replace with actual receiver token
    $message = 'Test push notification a first';
    $title = 'Test Notification';
    $data = [
        'id' => '123',
        'status' => 'pending',
        'type' => 'order',
    ];

    // Call sendNotification function to simulate sending a push notification
    $this->sendNotification($receivers, $message, $title, $data);

    

    return $this->successResponse(
        __('errors.' . ResponseError::SUCCESS, locale: $this->language),
        ['message' => 'Push notification sent successfully']
    );
}
	
	
	
	
	  public function testPushNotification(): JsonResponse
{
  
     $receivers = [
"dFsc9MejTBemITtkgoUHs8:APA91bG4ElfRA5T4ujbwmwi4al-co7Gcw00ajprrtleQtFN6yNzkZatOP6KbxN6GwPldyPNTImVli56kZEyB9mYaqGwootzVqwHEq7AjlfzKwemuuaRVHFE"];
    
    
    
    
    $message = 'Test push notification for order';
    $title = 'Test Notification';
    $data = [
        'id' => '123',
        'status' => 'pending',
        'type' => 'news_publish',
    ];

    // Call sendNotification function to simulate sending a push notification
    $this->sendNotification($receivers, $message, $title, $data);

    

    return $this->successResponse(
        __('errors.' . ResponseError::SUCCESS, locale: $this->language),
        ['message' => 'Push notification sent successfully']
    );
}


}
