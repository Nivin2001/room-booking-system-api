# Room Booking System API

A Laravel backend API for managing room and space reservations in coworking spaces, venues, schools, and hospitality properties.

## Overview

The API supports three main roles:

- **Customers**: browse spaces, check availability, book slots, pay, and manage reservations.
- **Staff**: manage spaces and categories, approve or reject bookings, upload media, and review reservations.
- **Admins**: manage users, categories, bookings, and view dashboard analytics.

## Key Features

- Laravel 12 backend with clean service layer separation
- Authentication and multi-role access control using Laravel Sanctum
- Space, category, booking, and availability management
- Stripe Checkout integration and webhook processing
- Booking lifecycle states with staff approval flow
- Event-driven notifications and email dispatch
- Spatie Media Library for space image uploads
- Queue-ready setup for async jobs and notifications

## Requirements

- PHP 8.2+
- Composer
- MySQL or compatible database
- Node.js and npm (for Vite asset tooling)
- Stripe account for payment integration

## Installation

1. Clone the repository:
   ```bash
   git clone <repo-url> room-booking-api
   cd room-booking-api
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Copy the environment template:
   ```bash
   cp .env.example .env
   ```

4. Generate the application key:
   ```bash
   php artisan key: generate
   ```

5. Configure `.env` values for database, mail, and Stripe.

6. Run migrations:
   ```bash
   php artisan migrate
   ```

7. Install frontend dependencies (optional):
   ```bash
   npm install
   ```

## Local Development

Start the Laravel development server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

If you want the Vite dev server too:

```bash
npm run dev
```

## Testing

Run the automated test suite:

```bash
vendor/bin/phpunit
```

Run a single test file or filter:

```bash
vendor/bin/phpunit --filter DashboardTest
```

## Useful Composer Scripts

- `composer install` — install PHP dependencies
- `php artisan migrate` — run database migrations
- `php artisan test` — run PHPUnit tests via Laravel wrapper
- `npm install` — install frontend dependencies
- `npm run dev` — start Vite development mode
- `npm run build` — build production assets
- `npm run setup` — install dependencies, generate key, migrate, and build assets

## API Routes

The API routes are registered in `routes/api.php` and use versioning under `/api/v1`.

### Public Routes

- `POST /api/v1/register`
- `POST /api/v1/login`
- `POST /api/v1/logout`
- `POST /api/v1/stripe/webhook`
- `GET /api/v1/payment/success`
- `GET /api/v1/payment/cancel`

### Customer Routes

Protected by Sanctum and customer role middleware.

- `GET /api/v1/customer/spaces`
- `GET /api/v1/customer/spaces/{id}`
- `GET /api/v1/customer/spaces/{id}/availability`
- `GET /api/v1/customer/spaces/{id}/slots`
- `POST /api/v1/customer/bookings`
- `GET /api/v1/customer/my-bookings`
- `PUT /api/v1/customer/bookings/{id}`
- `DELETE /api/v1/customer/bookings/{id}/cancel`
- `POST /api/v1/customer/payments/checkout`
- `GET /api/v1/customer/notifications`

### Staff Routes

Protected by Sanctum and staff role middleware.

- `GET /api/v1/staff/bookings`
- `PATCH /api/v1/staff/bookings/{id}/approve`
- `PATCH /api/v1/staff/bookings/{id}/reject`
- `GET /api/v1/staff/spaces`
- `POST /api/v1/staff/spaces`
- `PUT /api/v1/staff/spaces/{id}`
- `DELETE /api/v1/staff/spaces/{id}`
- `POST /api/v1/staff/spaces/{id}/categories`
- `POST /api/v1/staff/spaces/{space}/upload-image`
- `POST /api/v1/staff/spaces/{space}/upload-images`
- `DELETE /api/v1/staff/media/{id}`
- `POST /api/v1/staff/media/{id}/set-main`

### Admin Routes

Protected by Sanctum and admin role.

- `GET /api/v1/admin/dashboard`
- `GET /api/v1/admin/bookings`
- `GET /api/v1/admin/users`
- `PATCH /api/v1/admin/users/{id}/role`
- `DELETE /api/v1/admin/users/{id}`
- `GET /api/v1/admin/categories`
- `POST /api/v1/admin/categories`
- `PUT /api/v1/admin/categories/{id}`
- `DELETE /api/v1/admin/categories/{id}`

## API Examples

### Register a customer

```bash
curl -X POST http://127.0.0.1:8000/api/v1/register \
  -H "Content-Type: application/json" \
  -d '{"name": "Nevin", "email": "Nevin@example.com", "password": "secret", "password_confirmation": "secret"}'
```

### Customer login

```bash
curl -X POST http://127.0.0.1:8000/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email": "Nevin@example.com", "password": "secret"}'
```

### Create a Stripe checkout session

```bash
curl -X POST http://127.0.0.1:8000/api/v1/customer/payments/checkout \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"booking_id":123}'
```

### Create a booking request

```bash
curl -X POST http://127.0.0.1:8000/api/v1/customer/bookings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"space_id":45, "start_time": "2026-07-20 10:00:00", "end_time": "2026-07-20 12:00:00", "guests":4, "notes": "Team meeting"}'
```

### Access admin dashboard

```bash
curl -X GET http://127.0.0.1:8000/api/v1/admin/dashboard \
  -H "Authorization: Bearer <admin-token>."
```

## Booking Flow

A typical booking lifecycle in the application:

1. Customer selects a space and time slot.
2. Booking is created with `pending_payment` status.
3. Customer pays with Stripe Checkout.
4. Stripe webhook updates payment and booking status.
5. Booking becomes `pending_staff_approval`.
6. Staff approves or rejects the booking.
7. Approved bookings move to `confirmed`.
8. Rejected bookings may trigger refund processing.

## Payment Flow

Stripe payment lifecycle covers:

- `pending` -> `paid`
- `pending` -> `failed`
- `paid` -> `refunded`

The webhook service validates Stripe signatures and logs events to prevent duplicate processing.

## Project Structure

- `app/Http/Controllers` — request handling and API controller logic
- `app/Models` — Eloquent data models
- `app/Services` — business logic and Stripe processing
- `app/Events` / `app/Listeners` — event-driven workflows
- `app/Mail` / `app/Notifications` — email and notification templates
- `app/Policies` — authorization rules
- `database/migrations` — schema definitions
- `routes/api.php` — versioned API routing
- `tests` — automated feature and unit tests

## Notes

- Stripe requires credentials in `.env` for `STRIPE_KEY`, `STRIPE_SECRET`, and webhook settings.
- Media uploads are handled by Spatie Media Library and may require filesystem configuration.
- Queue workers are recommended for email, notifications, and webhook processing.
- The current project includes API-focused backend functionality; frontend assets are minimal.

## Environment Variables

The project requires these main `.env` values.

- `APP_NAME`
- `APP_URL`
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `QUEUE_CONNECTION`
- `MAIL_MAILER`
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_ENCRYPTION`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`
- `STRIPE_KEY`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`

## License

MIT

