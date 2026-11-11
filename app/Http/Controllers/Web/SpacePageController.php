<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Space;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SpacePageController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $query = Space::query()
                ->where('status', 'available')
                ->with('categories')
                ->latest();

            if (filled($filters['q'] ?? null)) {
                $term = trim($filters['q']);
                $query->where(function ($builder) use ($term) {
                    $builder->where('title', 'like', '%'.$term.'%')
                        ->orWhere('description', 'like', '%'.$term.'%')
                        ->orWhere('location', 'like', '%'.$term.'%');
                });
            }

            return view('spaces.index', [
                'spaces' => $query->paginate(9)->withQueryString(),
                'search' => $filters['q'] ?? '',
            ]);
        } catch (QueryException $exception) {
            Log::warning('Public space listing could not be loaded.', [
                'exception' => class_basename($exception),
            ]);

            return response()->view('spaces.unavailable', [], 503);
        }
    }

    public function show(int $space)
    {
        try {
            $space = Space::query()
                ->where('status', 'available')
                ->with('categories')
                ->findOrFail($space);
        } catch (QueryException $exception) {
            Log::warning('Public space details could not be loaded.', [
                'exception' => class_basename($exception),
            ]);

            return response()->view('spaces.unavailable', [], 503);
        }

        return view('spaces.show', compact('space'));
    }
}
