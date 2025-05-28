<?php

namespace App\Http\Controllers\API\v1\Rest;

set_time_limit(86400);

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    use ApiResponse;

    public function bosyaTest(Request $request)
    {
    }

}
