<?php

namespace App\Mcp\Tools;
use App\Models\Space;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\ResponseFactory;

#[Description(
    'Searches space listings by location, minimum capacity, and maximum hourly price. This tool does not confirm booking availability.'
)]
class SearchAvailableSpacesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()
                ->description('Optional location to search for.'),

            'min_capacity' => $schema->integer()
                ->description('Optional minimum number of people the space can accommodate.'),

            'max_price' => $schema->number()
                ->description('Optional maximum hourly price.'),
        ];
    }
public function handle(Request $request): ResponseFactory
    {
        $filters = $request->validate([
            'location' => ['sometimes', 'string', 'max:255'],
            'min_capacity' => ['sometimes', 'integer', 'min:1'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $spaces = Space::query()
            ->when(
                $filters['location'] ?? null,
                fn ($query, $location) =>
                    $query->where('location', 'like', "%{$location}%")
            )
            ->when(
                $filters['min_capacity'] ?? null,
                fn ($query, $capacity) =>
                    $query->where('capacity', '>=', $capacity)
            )
            ->when(
                isset($filters['max_price']),
                fn ($query) =>
                    $query->where(
                        'price_per_hour',
                        '<=',
                        $filters['max_price']
                    )
            )
            ->select([
                'id',
                'title',
                'location',
                'price_per_hour',
                'capacity',
                'status',
                'opening_hour',
                'closing_hour',
            ])
            ->orderBy('price_per_hour')
            ->limit(20)
            ->get();

        return Response::structured([
            'count' => $spaces->count(),
            'spaces' => $spaces->map(fn (Space $space) => [
                'id' => $space->id,
                'title' => $space->title,
                'location' => $space->location,
                'price_per_hour' => $space->price_per_hour,
                'capacity' => $space->capacity,
                'status' => $space->status,
                'opening_hour' => $space->opening_hour,
                'closing_hour' => $space->closing_hour,
            ])->values()->all(),
            'note' => 'These are space listings, not confirmed available booking slots.',
        ]);
    }
}
