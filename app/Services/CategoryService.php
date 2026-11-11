<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\CategoryRepository;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    protected $repo;

    public function __construct(CategoryRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Get all categories from cache or database.
     */
    public function list()
{
    return Cache::remember(
        'categories.all',
        now()->addHour(),
        function () {
            \Log::info('CATEGORY CACHE MISS - Database will be queried');

            return $this->repo->getAll();
        }
    );
}

    /**
     * Create a new category.
     */
    public function create(array $data)
    {
        $category = $this->repo->create($data);

        // Remove old cached categories
        Cache::forget('categories.all');

        return $category;
    }

    /**
     * Update an existing category.
     */
    public function update($id, array $data)
    {
        $category = Category::findOrFail($id);

        $category->update([
            'name' => $data['name'],
        ]);

        // Remove old cached categories
        Cache::forget('categories.all');

        return $category;
    }

    /**
     * Delete a category.
     */
    public function delete($id)
    {
        $category = Category::findOrFail($id);

        $category->delete();

        // Remove old cached categories
        Cache::forget('categories.all');
    }
}
