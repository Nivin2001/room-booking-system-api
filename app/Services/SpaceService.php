<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Repositories\SpaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
class SpaceService
{
    protected $repo;
    protected AvailabilityService $availabilityService;

    public function __construct(SpaceRepository $repo, AvailabilityService $availabilityService)
    {
        $this->repo = $repo;
        $this->availabilityService = $availabilityService;
    }


    public function create(array $data, User $creator): Space
    {
        if ($creator->role !== 'admin') {
            throw new AuthorizationException('Only administrators may create spaces.');
        }

        return DB::transaction(function () use ($data, $creator): Space {
            $staff = $this->requireStaff($data['staff_id'] ?? null, true);

            return Space::query()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'price_per_hour' => $data['price_per_hour'],
                'capacity' => $data['capacity'] ?? null,
                'status' => $data['status'],
                'created_by' => $creator->id,
                'staff_id' => $staff->id,
            ]);
        }, 3);
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
        ->whereIn('status', [
            BookingStatus::PendingPayment->value,
            BookingStatus::PendingStaffApproval->value,
            BookingStatus::Confirmed->value,
        ])
        ->orderBy('start_time')
        ->get();

    // Use the same one-hour candidates as web booking and the customer API.
    $availableSlots = collect($this->availabilityService->getSlots($space->id, $date))
        ->where('available', true)
        ->map(fn (array $slot): array => [
            'start' => $slot['start'],
            'end' => $slot['end'],
        ])
        ->values()
        ->all();

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

public function update($id, array $data, User $actor): Space
{
    if ($actor->role !== 'admin') {
        throw new AuthorizationException('Only administrators may update spaces.');
    }

    return DB::transaction(function () use ($id, $data): Space {
        // Lock the selected user before the space so role changes cannot race assignment.
        $staff = $this->requireStaff($data['staff_id'] ?? null, true);
        $space = Space::query()->lockForUpdate()->findOrFail($id);
        $space->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'price_per_hour' => $data['price_per_hour'],
            'capacity' => $data['capacity'] ?? null,
            'status' => $data['status'] ?? $space->status,
            'staff_id' => $staff->id,
        ]);

        if (array_key_exists('categories', $data)) {
            $space->categories()->sync($data['categories'] ?? []);
        }

        return $space->load(['categories', 'responsibleStaff']);
    }, 3);
}

public function delete($id)
{
    $space = Space::findOrFail($id);

    // 🔥 فصل العلاقة أول
    $space->categories()->detach();

    $space->delete();
}
public function list($filters = [], ?User $staff = null)
{
    $query = Space::query()->with('categories');

    if ($staff !== null) {
        $query->where('staff_id', $staff->id);
    }

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

public function listForStaff(User $staff, array $filters = [])
{
    return $this->list($filters, $staff);
}

private function requireStaff(mixed $staffId, bool $lock = false): User
{
    $staffId = filter_var($staffId, FILTER_VALIDATE_INT);
    if ($staffId === false || $staffId < 1) {
        throw new \DomainException('A responsible staff member is required.');
    }

    $query = User::query()->whereKey($staffId);
    if ($lock) {
        $query->lockForUpdate();
    }
    $staff = $query->where('role', 'staff')->first();
    if (! $staff) {
        throw new \DomainException('The selected responsible user must have the staff role.');
    }

    return $staff;
}
}
