<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\CategoryRepository;

class CategoryService
{
    protected $repo;

    public function __construct(CategoryRepository $repo)
    {
        $this->repo = $repo;
    }

    public function list()
    {
        return $this->repo->getAll();
    }

    public function create(array $data)
    {
        return $this->repo->create($data);
    }


    public function update($id, array $data)
    {
        $category = Category::findOrFail($id);

        $category->update([
            'name' => $data['name'],
        ]);

        return $category;
    }

    public function delete($id)
    {
        $category = Category::findOrFail($id);

        $category->delete();
    }
}
