# Room Booking System API

A Laravel backend API for managing room and space reservations in coworking spaces, venues, schools, and hospitality properties.

The system provides a complete reservation platform with authentication, role-based authorization, payment processing, notifications, reporting, and API documentation.

---

# Overview

The API supports three main roles:

## Customers

- Browse available spaces
- Check availability and booking slots
- Create and manage reservations
- Complete online payments
- Receive booking notifications

## Staff

- Manage spaces and categories
- Approve or reject bookings
- Upload and manage space images
- Review reservation requests

## Admins

- Manage users
- Manage categories
- Monitor bookings
- Access dashboard analytics and reports

---

# Key Features

- Laravel 12 backend with service layer architecture
- Authentication and multi-role access control using Laravel Sanctum
- Space, category, booking, and availability management
- Stripe Checkout integration and webhook processing
- Booking lifecycle management with staff approval workflow
- Event-driven notifications using Laravel Notifications
- Email and database notification channels
- Spatie Media Library for space image uploads
- Queue-ready architecture for background jobs
- Admin dashboard and reporting endpoints
- API documentation using Scribe

---

# Requirements

- PHP 8.2+
- Composer
- MySQL or compatible database
- Node.js and npm (optional for Vite assets)
- Stripe account for payment integration

---

# Installation

## 1. Clone Repository

```bash
git clone https://github.com/Nivin2001/room-booking-system-api.git

cd room-booking-system-api
```

---

## 2. Install PHP Dependencies

```bash
composer install
```

---

## 3. Environment Setup

Copy environment file:

```bash
cp .env.example .env
```

Generate application key:

```bash
php artisan key:generate
```

Configure:

- Database settings
- Mail configuration
- Queue settings
- Stripe credentials

---

## 4. Database Setup

Run migrations:

```bash
php artisan migrate
```

Create storage link:

```bash
php artisan storage:link
```

---

## 5. Install Frontend Dependencies (Optional)

```bash
npm install
```

---

# Local Development

Start Laravel server:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

For Vite development:

```bash
npm run dev
```

---

# Queue Processing

Run queue worker:

```bash
php artisan queue:work
```

Used for:

- Email notifications
- Background jobs
- Async processing

---

# Scheduler

Run Laravel scheduler:

```bash
php artisan schedule:work
```

Used for:

- Booking expiration
- Scheduled tasks

---

# Testing

Run all automated tests:

```bash
php artisan test
```

or:

```bash
vendor/bin/phpunit
```

Run specific test:

```bash
php artisan test --filter DashboardTest
```

## Test Coverage Areas

- Authentication
- Authorization
- Booking lifecycle
- Booking cancellation
- Payments
- Refunds
- Notifications
- Dashboard
- Webhook processing
- Space management

---

# API Documentation

The API documentation is generated using **Laravel Scribe**.

Generate documentation:

```bash
php artisan scribe:generate
```

Access documentation:

```
http://127.0.0.1:8000/docs
```

Includes:

- API endpoints
- Request examples
- Response examples
- Authentication details
- Postman collection
- OpenAPI specification

---

# API Routes

All API routes are versioned under:

```
/api/v1
```

---

# Public Routes

```
POST /api/v1/register

POST /api/v1/login

POST /api/v1/stripe/webhook

GET /api/v1/payment/success

GET /api/v1/payment/cancel
```

---

# Authenticated Routes

Requires:

```
Authorization: Bearer {token}
```

```
POST /api/v1/logout
```

---

# Customer Routes

Protected by:

```
auth:sanctum
role:customer
```

## Spaces

```
GET    /api/v1/customer/spaces

GET    /api/v1/customer/spaces/{id}

GET    /api/v1/customer/spaces/{id}/availability

GET    /api/v1/customer/spaces/{id}/slots
```

## Bookings

```
POST   /api/v1/customer/bookings

GET    /api/v1/customer/bookings

GET    /api/v1/customer/bookings/{id}

PUT    /api/v1/customer/bookings/{id}

PATCH  /api/v1/customer/bookings/{id}/cancel
```

## Payments

```
POST /api/v1/customer/payments/checkout
```

## Notifications

```
GET  /api/v1/customer/notifications

POST /api/v1/customer/notifications/{notification}/read

POST /api/v1/customer/notifications/read-all
```

---

# Staff Routes

Protected by:

```
auth:sanctum
role:staff
```

## Booking Management

```
GET   /api/v1/staff/bookings

PATCH /api/v1/staff/bookings/{id}/approve

PATCH /api/v1/staff/bookings/{id}/reject
```

## Space Management

```
GET    /api/v1/staff/spaces

POST   /api/v1/staff/spaces

PUT    /api/v1/staff/spaces/{id}

DELETE /api/v1/staff/spaces/{id}
```

## Media Management

```
POST   /api/v1/staff/spaces/{space}/upload-image

POST   /api/v1/staff/spaces/{space}/upload-images

DELETE /api/v1/staff/media/{id}

POST   /api/v1/staff/media/{id}/set-main
```

---

# Admin Routes

Protected by:

```
auth:sanctum
role:admin
```

## Dashboard and Reports

```
GET /api/v1/admin/dashboard

GET /api/v1/admin/reports/summary
```

## Users

```
GET    /api/v1/admin/users

PATCH  /api/v1/admin/users/{id}/role

DELETE /api/v1/admin/users/{id}
```

## Categories

```
GET    /api/v1/admin/categories

POST   /api/v1/admin/categories

PUT    /api/v1/admin/categories/{id}

DELETE /api/v1/admin/categories/{id}
```

---

# Booking Flow

Typical booking lifecycle:

```
Customer creates booking

        ↓

pending_payment

        ↓

Payment successful

        ↓

pending_staff_approval

        ↓

confirmed

        ↓

completed / cancelled / rejected
```

---

# Payment Flow

Stripe payment lifecycle:

```
pending → paid

pending → failed

paid → refunded
```

The webhook service validates Stripe signatures and prevents duplicate event processing.

---

# Notification System

The application uses Laravel Notification architecture.

Supported notifications:

- Booking Created
- Booking Approved
- Booking Rejected
- Booking Cancelled
- Payment Successful
- Payment Failed
- Refund Completed

Notification flow:

```
Event

↓

Listener

↓

Notification

↓

Email + Database
```

---

# Architecture Overview

The project follows a layered backend architecture:

```
Routes

↓

Controllers

↓

Services

↓

Models

↓

Database
```

Event-driven workflow:

```
Events

↓

Listeners

↓

Notifications

↓

Queue
```

---

# Project Structure

```text
app/
├── Http/Controllers
├── Models
├── Services
├── Events
├── Listeners
├── Notifications
└── Policies

database/
└── migrations

routes/
└── api.php

tests/
└── Feature
```

---

# Environment Variables

Required:

```
APP_NAME
APP_URL

DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD

QUEUE_CONNECTION

MAIL_MAILER
MAIL_HOST
MAIL_PORT
MAIL_USERNAME
MAIL_PASSWORD

STRIPE_KEY
STRIPE_SECRET
STRIPE_WEBHOOK_SECRET
```

---

# Notes

- Stripe credentials are required for payment processing.
- Spatie Media Library handles file uploads.
- Queue workers are recommended for notifications and background tasks.
- The project focuses on backend API development.

---

# Future Improvements

- Increase automated test coverage
- Add Redis caching
- Add WebSocket real-time notifications
- Add CI/CD pipeline
- Docker deployment
- Advanced analytics dashboard

---

# License

MIT

---

# Author

**Nivin Shabat**

Laravel Backend Developer
