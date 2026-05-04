<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Services\SpaceService;
use App\Http\Resources\SpaceResource;
use App\Models\Space;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SpaceController extends Controller
{
     use AuthorizesRequests, ValidatesRequests;
    protected $service;

    public function __construct(SpaceService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
{
    $spaces = $this->service->list($request->all());

    return SpaceResource::collection($spaces);
}

      public function store(Request $request)
{
    $request->validate([
        'title' => 'required|string',
        'description' => 'nullable|string',
        'location' => 'required|string',
        'price_per_hour' => 'required|numeric',
        'capacity' => 'required|integer',
        'status' => 'required|in:available,unavailable',
        'categories' => 'required|array',
        'categories.*' => 'exists:categories,id',
    ]);

    // 📦 إنشاء space عبر service
    $space = $this->service->create($request->all());

    // 🔗 ربط categories (many-to-many)
    $space->categories()->sync($request->categories);

    return response()->json([
        'success' => true,
        'message' => 'Space created successfully',
        'data' => $space->load('categories')
    ], 201);
}


    /**
     * 🏢 Get all spaces (with filters)
     */
    // public function index(Request $request)
    // {
    //     $spaces = $this->service->getCustomerSpaces($request->all());

    //     return SpaceResource::collection($spaces);
    // }

    /**
     * 🔍 Show single space
     */
    public function show($id)
    {
        $space = $this->service->getSpaceDetails($id);

        return new SpaceResource($space);
    }

    /**
     * 📅 Availability calendar
     */
 public function availability($spaceId, Request $request)
{
    $space = Space::find($spaceId);

    if (!$space) {
        return response()->json([
            'success' => false,
            'message' => 'Space not found'
        ], 404);
    }

    $date = $request->date ?? now()->toDateString();

    $availability = $this->service->getAvailability($space, $date);

    return response()->json([
        'success' => true,
        'data' => $availability
    ]);
}

public function update(Request $request, Space $space)
{
    $request->validate([
        'title' => 'required|string',
        'description' => 'nullable|string',
        'location' => 'required|string',
        'price_per_hour' => 'required|numeric',
        'capacity' => 'required|integer',
        'status' => 'in:available,unavailable',
        'categories' => 'array',
        'categories.*' => 'exists:categories,id'
    ]);

    // 🔐 policy check
    $this->authorize('update', $space);

    $space = $this->service->update($space->id, $request->validated());

    return new SpaceResource($space);
}

public function destroy($id)
{
    $space = Space::findOrFail($id);

    // 🔐 policy check
    $this->authorize('delete', $space);

    $this->service->delete($id);

    return response()->json([
        'message' => 'Deleted successfully'
    ]);
}

public function syncCategories(Request $request, $id)
{
    $request->validate([
        'categories' => 'required|array',
        'categories.*' => 'exists:categories,id'
    ]);

    $space = Space::findOrFail($id);

    // 🔥 الربط
    $space->categories()->sync($request->categories);

    return response()->json([
        'message' => 'Categories synced successfully',
        'data' => $space->load('categories')
    ]);
}
public function uploadImage(Request $request, Space $space)
{
    $request->validate([
        'image' => 'required|image|mimes:jpg,jpeg,png|max:2048'
    ]);

    $this->authorize('update', $space);

    $space->addMediaFromRequest('image')
          ->toMediaCollection('images');

    return response()->json([
        'message' => 'Image uploaded successfully'
    ]);
}

public function uploadMultipleImages(Request $request, Space $space)
{
    $request->validate([
        'images' => 'required|array',
        'images.*' => 'image|mimes:jpg,jpeg,png|max:2048'
    ]);

    $this->authorize('update', $space);

    foreach ($request->file('images') as $image) {
        $space->addMedia($image)->toMediaCollection('images');
    }

    return response()->json([
        'message' => 'Images uploaded successfully'
    ]);
}
  public function downloadImage($id)
{
    $media = Media::findOrFail($id);

 return response()->download(
    $media->getPath(),
    $media->file_name,
    [
        'Content-Type' => $media->mime_type,
        'Content-Disposition' => 'attachment; filename="'.$media->file_name.'"'
    ]
);
}


public function deleteImage($id)
{
    $media = Media::findOrFail($id);

    // $this->authorize('update', $media->model);

    $media->delete();

    return response()->json([
        'message' => 'Image deleted successfully'
    ]);
}

public function setMainImage($id)
{
    $media = Media::findOrFail($id);

    $model = $media->model;

    $model->media()
        ->where('collection_name', 'images')
        ->update(['order_column' => 999]);

    $media->update(['order_column' => 1]);

    return response()->json([
        'message' => 'Main image set successfully'
    ]);
}

}
