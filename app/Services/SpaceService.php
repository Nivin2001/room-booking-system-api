<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Space;
use App\Repositories\SpaceRepository;
class SpaceService
{
    protected $repo;

    public function __construct(SpaceRepository $repo)
    {
        $this->repo = $repo;
    }


    public function create(array $data)
{
    return Space::create([
        'title' => $data['title'],
        'description' => $data['description'] ?? null,
        'location' => $data['location'],
        'price_per_hour' => $data['price_per_hour'],
        'capacity' => $data['capacity'],
        'status' => $data['status'],

        'created_by' => auth()->id(),
    ]);
}
    public function getCustomerSpaces(array $filters)
{
    $query = Space::with('categories');

    if (!empty($filters['category_id'])) {
        $query->whereHas('categories', function ($q) use ($filters) {
            $q->where('categories.id', $filters['category_id']);
        });
    }

    if (!empty($filters['min_price']) && !empty($filters['max_price'])) {
        $query->whereBetween('price_per_hour', [
            $filters['min_price'],
            $filters['max_price']
        ]);
    }

    if (!empty($filters['capacity'])) {
        $query->where('capacity', '>=', $filters['capacity']);
    }

    return $query->latest()->paginate(10);
}
  public function getSpaceDetails($id)
{
    return Space::with('categories')
        ->findOrFail($id);
}
  public function getAvailability(Space $space, $date)
{
    $bookings = Booking::where('space_id', $space->id)
        ->whereDate('start_time', $date)
        ->where('status', '!=', 'cancelled')
        ->orderBy('start_time')
        ->get();

    // 🧠 define working hours
    $startDay = $date . ' 08:00:00';
    $endDay   = $date . ' 20:00:00';

    $availableSlots = [];
    $currentStart = $startDay;

    foreach ($bookings as $booking) {
        if ($currentStart < $booking->start_time) {
            $availableSlots[] = [
                'start' => $currentStart,
                'end'   => $booking->start_time,
            ];
        }

        $currentStart = $booking->end_time;
    }

    // آخر فترة بعد آخر حجز
    if ($currentStart < $endDay) {
        $availableSlots[] = [
            'start' => $currentStart,
            'end'   => $endDay,
        ];
    }

    return [
        'space_id' => $space->id,
        'date' => $date,
        'booked_slots' => $bookings->map(fn($b) => [
            'start' => $b->start_time,
            'end' => $b->end_time,
        ]),
        'available_slots' => $availableSlots
    ];
}

public function update($id, array $data)
{
    $space = Space::findOrFail($id);

    $space->update([
        'title' => $data['title'],
        'description' => $data['description'],
        'location' => $data['location'],
        'price_per_hour' => $data['price_per_hour'],
        'capacity' => $data['capacity'],
        'status' => $data['status'] ?? $space->status,
    ]);

    // 🔥 تحديث categories (many-to-many)
    if (!empty($data['categories'])) {
        $space->categories()->sync($data['categories']);
    }

    return $space->load('categories');
}

public function delete($id)
{
    $space = Space::findOrFail($id);

    // 🔥 فصل العلاقة أول
    $space->categories()->detach();

    $space->delete();
}
  public function list($filters = [])
{
    $query = Space::query()->with('categories');

    // 🔍 search
    if (!empty($filters['search'])) {
        $query->where(function ($q) use ($filters) {
            $q->where('title', 'like', '%' . $filters['search'] . '%')
              ->orWhere('description', 'like', '%' . $filters['search'] . '%');
        });
    }

    // 💰 price range
    if (!empty($filters['min_price'])) {
        $query->where('price_per_hour', '>=', $filters['min_price']);
    }

    if (!empty($filters['max_price'])) {
        $query->where('price_per_hour', '<=', $filters['max_price']);
    }

    // 👥 capacity
    if (!empty($filters['capacity'])) {
        // $query->where('capacity', '>=', $filters['capacity']);
        $query->where('capacity', $filters['capacity']);
    }

    // 🏷️ category
    if (!empty($filters['category_id'])) {
        $query->whereHas('categories', function ($q) use ($filters) {
            $q->where('categories.id', $filters['category_id']);
        });
    }

    // 🔄 sorting
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'price_asc':
                $query->orderBy('price_per_hour', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price_per_hour', 'desc');
                break;
            case 'newest':
                $query->latest();
                break;
        }
    }

    return $query->get();
}
}
