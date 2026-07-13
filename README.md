# Room Booking System API

A Laravel backend API for managing room and space reservations inside facilities such as coworking spaces, event venues, educational institutions, and hospitality properties.

## Overview

This API supports three main roles:

- **Customers**: browse available spaces, book time slots, pay, and manage reservations.
- **Staff**: manage spaces, review bookings, approve or reject reservations, and upload media.
- **Admins**: manage categories, users, view bookings, and monitor revenue.

## Key Features

- Authentication with Laravel Sanctum
- Role-based access control for customers, staff, and admins
- Space and category management
- Availability checks and hourly booking slots
- Stripe payment checkout and webhook processing
- Booking approval/rejection workflow
- Event-driven notifications and email messaging
- Media upload via Spatie Media Library
- Queue-ready background processing

## Requirements

- PHP 8.2+
- Laravel 12
- MySQL
- Composer
- Node.js / npm (for Vite assets)

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
3. Copy environment file:
   ```bash
   cp .env.example .env
   ```
4. Generate application key:
   ```bash
   php artisan key:generate
   ```
5. Configure database and mail settings in `.env`
6. Run migrations:
   ```bash
   php artisan migrate
   ```
7. Install frontend dependencies (optional):
   ```bash
   npm install
   ```

## Usage

Start the application locally:

```bash
php artisan serve
```

Or use the dev script from `package.json`:

```bash
npm run dev
```

## Available Scripts

- `composer install` — install PHP dependencies
- `php artisan migrate` — run database migrations
- `php artisan test` — run automated tests
- `npm install` — install frontend dependencies
- `npm run dev` — run Vite development server
- `npm run build` — build frontend assets

## Routes and API Structure

Routes are defined under `routes/api.php` and use versioning and role-based middleware.

### Public Endpoints

- `POST /api/v1/register`
- `POST /api/v1/login`
- `POST /api/v1/logout`
- `POST /api/v1/stripe/webhook`
- `GET /api/v1/payment/success`
- `GET /api/v1/payment/cancel`

### Customer Endpoints

Protected by Sanctum and `customer` role:

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

### Staff Endpoints

Protected by Sanctum and `staff` role:

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

### Admin Endpoints

Admin routes are typically under `/api/v1/admin` and include user, category, and booking management.

## Project Structure

- `app/Http/Controllers` — API controllers and request handling
- `app/Models` — Eloquent models
- `app/Services` — business logic services
- `app/Events` and `app/Listeners` — event-driven architecture
- `app/Mail` and `app/Notifications` — email and notification handlers
- `database/migrations` — database schema definitions
- `routes/api.php` — API route definitions

## Notes

- Payment processing uses Stripe and requires valid Stripe credentials in `.env`
- Media upload uses Spatie Media Library and requires filesystem configuration
- Queue workers are recommended for processing email and event listeners
- This README is intended to document the current backend API; frontend-specific assets are minimal and managed by Vite

## License

MIT


Protected by Sanctum authentication and `admin` role.

- `GET /admin/users`
- `PATCH /admin/users/{id}/role`
- `DELETE /admin/users/{id}`
- `GET /admin/categories`
- `POST /admin/categories`
- `PUT /admin/categories/{id}`
- `DELETE /admin/categories/{id}`
- `GET /admin/bookings`
- `GET /admin/dashboard`

## Booking Lifecycle

The intended booking flow is:

```text
Customer selects slot
-> booking created as pending_payment
-> customer pays through Stripe Checkout
-> webhook marks payment as paid
-> booking becomes pending_staff_approval
-> staff approves or rejects
-> approved booking becomes confirmed
-> rejected booking triggers refund flow
```

Alternative flows:

```text
pending_payment -> payment_failed
pending_payment -> expired
pending_payment -> cancelled
pending_staff_approval -> rejected -> refunded
confirmed -> refund requested -> refunded/cancelled
```

## Payment Lifecycle

The intended payment flow is:

```text
pending -> paid -> refunded
pending -> failed
paid -> partially_refunded
paid -> disputed
```

Current Stripe support includes checkout session creation and webhook handlers for successful checkout, failed payment intent, and refunded charges.

## Setup

1. Install PHP dependencies:

```bash
composer install
```

2. Create environment file:

```bash
cp .env.example .env
```

3. Generate application key:

```bash
php artisan key:generate
```

4. Configure database, mail, queue, and Stripe values in `.env`.

5. Run migrations:

```bash
php artisan migrate
```

6. Start the local server:

```bash
php artisan serve
```

7. Start the queue worker when testing queued notifications:

```bash
php artisan queue:work
```

## Important Environment Values

The application depends on the usual Laravel environment values plus payment and mail settings.

Common values:

- `APP_URL`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `QUEUE_CONNECTION`
- `MAIL_MAILER`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`

## Testing

Run the test suite with:

```bash
php artisan test
```

Current test coverage is minimal. The next development phase should add feature tests for authentication, authorization, booking availability, payment checkout, Stripe webhooks, approval/rejection, refunds, and dashboard calculations.

## Next Development Phase

The next phase should focus on production hardening before adding more features:

1. Fix authorization order for staff booking approval/rejection.
2. Strengthen booking lifecycle and status transitions.
3. Fix and complete refund handling.
4. Add webhook idempotency using webhook logs.
5. Add feature tests for core business flows.
6. Improve dashboard calculations and reporting.
7. Generate API documentation using Scribe.

See [docs/ROADMAP.md](docs/ROADMAP.md) for the detailed roadmap.
>>>>>>> theirs
