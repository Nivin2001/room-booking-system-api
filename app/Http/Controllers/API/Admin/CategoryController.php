<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\CategoryService;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    protected $service;


   public function __construct(CategoryService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return CategoryResource::collection($this->service->list());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:categories,name'
        ]);

        return new CategoryResource(
            $this->service->create($request->all())
        );
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|unique:categories,name,' . $id
        ]);

        return new CategoryResource(
            $this->service->update($id, $request->all())
        );
    }

    public function destroy($id)
    {
        $this->service->delete($id);

        return response()->json([
            'message' => 'Deleted successfully'
        ]);
    }
}
