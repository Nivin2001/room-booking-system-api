<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    //
     public function __construct(
        protected ReportService $service
    ){}


    public function summary()
    {

        return response()->json([

            'success' => true,

            'data' => $this->service->summary()

        ]);

    }
}
