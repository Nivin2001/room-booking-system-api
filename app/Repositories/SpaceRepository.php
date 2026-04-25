<?php

namespace App\Repositories;

use App\Models\Space;

class SpaceRepository
{
    public function getAll()
    {
        return Space::with('categories')->latest()->get();
    }

    public function find($id)
    {
        return Space::with('categories')->findOrFail($id);
    }

    public function create(array $data)
    {
        $space = Space::create($data);

        if (isset($data['categories'])) {
            $space->categories()->sync($data['categories']);
        }

        return $space;
    }

    public function update($id, array $data)
    {
        $space = Space::findOrFail($id);

        $space->update($data);

        if (isset($data['categories'])) {
            $space->categories()->sync($data['categories']);
        }

        return $space;
    }

    public function delete($id)
    {
        $space = Space::findOrFail($id);
        return $space->delete();
    }
}
