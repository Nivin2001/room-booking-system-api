<<<<<<< ours

# Room Booking System API

A Room Booking API system that supports three main actors: Customer, Staff, and Admin.

## 🚀 Features
- Authentication using Laravel Sanctum
- Booking system with conflict prevention using database transactions
- Event-driven architecture (Events & Listeners)
- Queue system for background processing
- Notification system (Mail & Database)

## 🛠️ Tech Stack
- Laravel
- MySQL
- Queue (Database)
- Sanctum Authentication

## 🎯 Architecture
- Controller → Service Layer → Event → Listener → Queue → Notification

## 📌 Goal
Build a scalable and high-performance booking system using clean architecture principles.
=======
# Room Booking System API

Room Booking System API is a Laravel backend application for reserving and managing rooms or spaces inside a facility. It is designed for use cases such as coworking spaces, educational institutions, event venues, hospitality businesses, and internal facility management.

The system supports three main stakeholders:

- Administrators: manage users, categories, bookings, and analytics.
- Staff: manage spaces, review bookings, approve or reject reservations, and maintain room information.
- Customers: browse available spaces, create bookings, pay for reservations, and manage their own bookings.

## Project Status

This project currently implements the core backend workflow for authentication, role-based access, spaces, bookings, payments, media uploads, notifications, and basic analytics.

Current maturity: backend API in active development. The main business modules are present, but production hardening is still needed around tests, authorization consistency, payment/refund edge cases, webhook idempotency, and concurrency-safe booking.

## Implemented Features

### Authentication and Access Control

- Customer registration and login.
- Token-based authentication using Laravel Sanctum.
- Logout by deleting user tokens.
- Role middleware for `customer`, `staff`, and `admin`.
- Initial policy classes for spaces and bookings.

### Space and Room Management

- Staff can create, update, delete, and list spaces.
- Spaces include title, description, location, hourly price, capacity, status, and creator.
- Spaces can be linked to categories through a many-to-many relationship.
- Search and filtering by keyword, price, capacity, category, and sorting.
- Image upload support using Spatie Media Library.
- Multiple images, image deletion, image download, and setting a main image.

### Booking Management

- Customers can view spaces and available slots.
- Customers can create bookings for available slots.
- Customers can view their own bookings.
- Customers can update pending bookings.
- Customers can cancel bookings when allowed by business rules.
- Staff can view bookings and approve or reject bookings.
- Booking lifecycle uses status values such as:
  - `pending_payment`
  - `payment_failed`
  - `pending_staff_approval`
  - `confirmed`
  - `rejected`
  - `cancelled`
  - `expired`

### Availability and Pricing

- Availability service checks whether a space is free for a time range.
- Slot generation currently creates hourly slots.
- Pricing service calculates total booking amount from space hourly price and selected time range.

### Payments

- Stripe Checkout integration.
- Internal payment records are created before redirecting to Stripe Checkout.
- Stripe webhook verifies event signatures.
- Payment success updates payment and booking status.
- Payment failure and refund event handlers exist.
- Payment transaction table exists for future ledger/history support.

### Notifications and Background Processing

- Event-driven structure using events and listeners.
- Mail classes for booking and payment-related messages.
- Notification classes for booking lifecycle updates.
- Reminder command/job structure for upcoming bookings.
- Queue configuration is available through Laravel.

### Admin and Reporting

- Admin can manage users and categories.
- Admin can view bookings.
- Basic dashboard statistics:
  - total bookings
  - bookings today
  - total revenue
  - top booked spaces

## Partially Implemented or Needs Hardening

- Real-time availability updates are not fully implemented yet. Current availability is API-based, not websocket-based.
- SMS notifications are not implemented yet.
- Reports are currently basic dashboard statistics, not full exportable reports.
- Refund and dispute handling need a more complete payment lifecycle.
- Webhook idempotency needs to be completed using webhook logs.
- Double-booking prevention needs stronger concurrency handling.
- Authorization should be checked before state-changing actions.
- Feature tests are still minimal.
- API documentation should be generated and maintained through Scribe or Postman.
- Mobile responsiveness is mainly a frontend concern; this API is designed to support mobile and responsive clients.

## Tech Stack

- PHP 8.2+
- Laravel 12
- Laravel Sanctum
- MySQL
- Stripe PHP SDK
- Spatie Laravel Media Library
- SendGrid package
- Laravel Queues
- PHPUnit
- Scribe for API documentation

## Architecture

The project follows a layered backend style:

```text
Routes -> Controllers -> Services -> Models/Repositories -> Events -> Listeners -> Mail/Notifications/Queue
```

Important application areas:

- `routes/api.php`: API route definitions and role-protected route groups.
- `app/Http/Controllers/API`: HTTP request handling by actor area.
- `app/Services`: business logic for bookings, spaces, availability, pricing, dashboard, and Stripe.
- `app/Models`: Eloquent models for users, spaces, categories, bookings, payments, webhook logs, and transactions.
- `app/Events` and `app/Listeners`: event-driven booking and payment side effects.
- `app/Mail` and `app/Notifications`: email and database notification support.
- `database/migrations`: database schema.

## Main API Areas

All API routes are grouped under `/api/v1`.

### Public

- `POST /register`
- `POST /login`
- `POST /logout`
- `POST /stripe/webhook`
- `GET /payment/success`
- `GET /payment/cancel`

### Customer

Protected by Sanctum authentication and `customer` role.

- `GET /customer/spaces`
- `GET /customer/spaces/{id}`
- `GET /customer/spaces/{id}/availability`
- `GET /customer/spaces/{id}/slots`
- `POST /customer/bookings`
- `GET /customer/my-bookings`
- `PUT /customer/bookings/{id}`
- `DELETE /customer/bookings/{id}/cancel`
- `POST /customer/payments/checkout`
- `GET /customer/notifications`

### Staff

Protected by Sanctum authentication and `staff` role.

- `GET /staff/bookings`
- `PATCH /staff/bookings/{id}/approve`
- `PATCH /staff/bookings/{id}/reject`
- `GET /staff/spaces`
- `POST /staff/spaces`
- `PUT /staff/spaces/{id}`
- `DELETE /staff/spaces/{id}`
- `POST /staff/spaces/{id}/categories`
- `POST /staff/spaces/{space}/upload-image`
- `POST /staff/spaces/{space}/upload-images`
- `DELETE /staff/media/{id}`
- `POST /staff/media/{id}/set-main`

### Admin

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
