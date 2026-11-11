<?php

namespace App\Http\Controllers\Web;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Space;
use App\Models\User;
use App\Services\SpaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminDashboardPageController
{
    public function __construct(private readonly SpaceService $spaceService) {}

    public function index()
    {
        $stats = [
            'users' => User::query()->count(),
            'spaces' => Space::query()->count(),
            'bookings' => Booking::query()->count(),
            'pending' => Booking::query()->whereIn('status', [BookingStatus::PendingPayment, BookingStatus::PendingStaffApproval])->count(),
            'confirmed' => Booking::query()->where('status', BookingStatus::Confirmed)->count(),
            'paid' => Payment::query()->where('status', PaymentStatus::Paid)->count(),
            'revenueByCurrency' => Payment::query()->where('status', PaymentStatus::Paid)
                ->selectRaw('currency, SUM(amount) as total')->groupBy('currency')->orderBy('currency')->get(),
            'refunds' => Payment::query()->whereIn('status', [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded])->count(),
            'conflicts' => PaymentTransaction::query()->where('type', 'payment_conflict')->count(),
        ];
        $recentBookings = Booking::query()->with(['space', 'user', 'payment'])->latest()->limit(8)->get();

        return view('admin.dashboard', compact('stats', 'recentBookings'));
    }

    public function spaces(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['available', 'unavailable'])],
        ]);
        $query = Space::query()->with(['categories', 'responsibleStaff'])->withCount('bookings')->latest();
        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder->where('title', 'like', '%'.$term.'%')->orWhere('location', 'like', '%'.$term.'%'));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        $spaces = $query->paginate(15)->withQueryString();

        return view('admin.spaces.index', compact('spaces', 'filters'));
    }

    public function createSpace()
    {
        return view('admin.spaces.form', [
            'space' => new Space,
            'categories' => Category::query()->orderBy('name')->get(),
            'staffUsers' => User::query()->where('role', 'staff')->orderBy('name')->get(),
            'editing' => false,
        ]);
    }

    public function storeSpace(Request $request)
    {
        abort_unless($request->user()->can('create', Space::class), 403);
        $validated = array_merge(['location' => null, 'description' => null, 'capacity' => null], $this->validateSpace($request));
        $space = $this->spaceService->create($validated, $request->user());
        $space->categories()->sync($validated['categories'] ?? []);
        if ($request->hasFile('image')) {
            $space->addMediaFromRequest('image')->toMediaCollection('images');
        }

        return redirect()->route('admin.spaces.index')->with('auth_notice', 'تم إنشاء المساحة.');
    }

    public function editSpace(Space $space)
    {
        return view('admin.spaces.form', [
            'space' => $space->load(['categories', 'responsibleStaff']),
            'categories' => Category::query()->orderBy('name')->get(),
            'staffUsers' => User::query()->where('role', 'staff')->orderBy('name')->get(),
            'editing' => true,
        ]);
    }

    public function updateSpace(Request $request, Space $space)
    {
        abort_unless($request->user()->can('update', $space), 403);
        $validated = array_merge(['location' => $space->location, 'description' => $space->description, 'capacity' => $space->capacity], $this->validateSpace($request, true));
        $previousStaffId = (int) $space->staff_id;
        $space = $this->spaceService->update($space->id, $validated, $request->user());
        if ($previousStaffId !== (int) $space->staff_id) {
            Log::info('Space staff assignment changed.', [
                'space_id' => $space->id,
                'previous_staff_id' => $previousStaffId ?: null,
                'new_staff_id' => $space->staff_id,
                'changed_by' => $request->user()->id,
            ]);
        }
        if ($request->hasFile('image')) {
            $space->addMediaFromRequest('image')->toMediaCollection('images');
        }

        return redirect()->route('admin.spaces.index')->with('auth_notice', 'تم تحديث المساحة.');
    }

    public function toggleSpace(Request $request, Space $space)
    {
        abort_unless($request->user()->can('update', $space), 403);
        $space->update(['status' => $space->status === 'available' ? 'unavailable' : 'available']);

        return back()->with('auth_notice', 'تم تحديث حالة المساحة.');
    }

    public function deleteSpace(Space $space)
    {
        abort_unless(request()->user()?->can('delete', $space), 403);
        if ($space->bookings()->exists()) {
            return back()->withErrors(['space' => 'لا يمكن حذف مساحة مرتبطة بحجوزات؛ غيّر حالتها إلى غير متاحة بدلًا من ذلك.']);
        }
        try {
            $this->spaceService->delete($space->id);
        } catch (\Throwable $exception) {
            Log::warning('Admin could not delete an unused space.', ['space_id' => $space->id, 'exception' => class_basename($exception)]);
            return back()->withErrors(['space' => 'تعذر حذف المساحة.']);
        }

        return redirect()->route('admin.spaces.index')->with('auth_notice', 'تم حذف المساحة.');
    }

    public function categories()
    {
        $categories = Category::query()->withCount('spaces')->orderBy('name')->paginate(20);
        return view('admin.categories.index', compact('categories'));
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:categories,name'], 'description' => ['nullable', 'string', 'max:2000']]);
        Category::query()->create($data);
        return back()->with('auth_notice', 'تمت إضافة التصنيف.');
    }

    public function updateCategory(Request $request, Category $category)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)], 'description' => ['nullable', 'string', 'max:2000']]);
        $category->update($data);
        return back()->with('auth_notice', 'تم تحديث التصنيف.');
    }

    public function deleteCategory(Category $category)
    {
        if ($category->spaces()->exists()) {
            return back()->withErrors(['category' => 'لا يمكن حذف تصنيف مرتبط بمساحات.']);
        }
        $category->delete();
        return back()->with('auth_notice', 'تم حذف التصنيف.');
    }

    public function bookings(Request $request)
    {
        $statuses = array_column(BookingStatus::cases(), 'value');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'q' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = Booking::query()->with(['user', 'space', 'payment'])->latest();
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['date'] ?? null)) $query->whereDate('start_time', $filters['date']);
        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('id', is_numeric($term) ? (int) $term : -1)
                    ->orWhereHas('user', fn ($users) => $users->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))
                    ->orWhereHas('space', fn ($spaces) => $spaces->where('title', 'like', '%'.$term.'%'));
            });
        }
        $bookings = $query->paginate(20)->withQueryString();
        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'filters' => $filters,
            'statuses' => BookingStatus::cases(),
        ]);
    }

    public function showBooking(Request $request, Booking $booking)
    {
        abort_unless($request->user()->can('view', $booking), 403);

        return view('admin.bookings.show', [
            'booking' => $booking->load(['user', 'space.categories', 'payment.transactions', 'payment.refundOperations']),
        ]);
    }

    public function payments()
    {
        $payments = Payment::query()->with(['booking.user', 'booking.space', 'transactions', 'refundOperations'])->latest()->paginate(15, ['*'], 'payments_page');
        $conflicts = PaymentTransaction::query()->with(['payment.booking.user', 'payment.booking.space'])
            ->where('type', 'payment_conflict')->latest()->paginate(10, ['*'], 'conflicts_page');
        return view('admin.payments.index', compact('payments', 'conflicts'));
    }

    private function validateSpace(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:255'],
            'price_per_hour' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['required', Rule::in(['available', 'unavailable'])],
            'staff_id' => ['required', Rule::exists('users', 'id')->where('role', 'staff')],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
    }
}
