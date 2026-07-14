# Room Booking System API

A Laravel backend API for managing room and space reservations in coworking spaces, venues, schools, and hospitality properties.

The system provides secure booking management, role-based access control, online payments, notifications, reporting, and administrative features through a RESTful API architecture.

---

# Overview

The API supports three main user roles:

- **Customers**
  - Browse available spaces
  - Check availability and time slots
  - Create and manage reservations
  - Complete online payments
  - Receive notifications

- **Staff**
  - Manage spaces
  - Manage categories
  - Upload and manage media
  - Approve or reject booking requests
  - Review reservations

- **Admins**
  - Manage users
  - Manage categories
  - Monitor bookings
  - Access dashboard analytics
  - Generate reports

---

# Key Features

## Authentication & Authorization

- Laravel 12 API backend
- Laravel Sanctum authentication
- Token-based authentication
- Role-based access control

Supported roles:


Customer
Staff
Admin


---

## Space Management

- Create and update spaces
- Delete spaces
- Assign categories
- Upload images
- Manage media using Spatie Media Library
- Check space availability
- Generate available booking slots

---

## Booking Management

Customers can:

- Create bookings
- View their reservations
- Update bookings
- Cancel bookings
- Track booking status


## Booking Lifecycle


Booking Created

    ↓

pending_payment

    ↓

Payment Successful

    ↓

pending_staff_approval

    ↓

confirmed

    ↓

completed / cancelled / rejected


The system validates booking status transitions to prevent invalid workflow changes.

---

# Payment System

Integrated with Stripe Checkout.

Supported payment operations:

- Create Stripe Checkout Session
- Handle successful payments
- Handle failed payments
- Process refunds
- Verify Stripe webhook signatures
- Prevent duplicate webhook processing


## Payment Lifecycle


pending

↓

paid

↓

refunded


or:


pending

↓

failed


---

# Notification System

The system uses Laravel Notification architecture with database and email channels.

Implemented notifications:

- Booking Created
- Booking Approved
- Booking Rejected
- Booking Cancelled
- Payment Succeeded
- Payment Failed
- Refund Completed


Notification flow:


Event

↓

Listener

↓

Notification

↓

Email + Database


Notifications are queue-ready for asynchronous processing.

---

# Reports & Dashboard

Admin reporting features include:

- Total bookings
- Today's bookings
- Revenue summary
- Most booked spaces
- Booking statistics
- User management overview

Available through:


GET /api/v1/admin/dashboard


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
2. Install PHP Dependencies
composer install
3. Environment Configuration

Create .env file:

cp .env.example .env

Generate application key:

php artisan key:generate

Configure:

Database
Mail
Queue
Stripe credentials
4. Database Setup

Run migrations:

php artisan migrate --seed

Create storage link:

php artisan storage:link
5. Run Application

Start Laravel server:

php artisan serve --host=127.0.0.1 --port=8000
Queue Processing

Run queue worker for background jobs:

php artisan queue:work

Used for:

Email notifications
Background processing
Async tasks
Scheduler

Run Laravel scheduler:

php artisan schedule:work

Used for:

Booking expiration
Scheduled tasks
Testing

Run all tests:

php artisan test

or:

vendor/bin/phpunit

Run specific test:

php artisan test --filter DashboardTest

Test coverage includes:

Authentication
Authorization
Booking creation
Booking cancellation
Booking status lifecycle
Payments
Refunds
Notifications
Dashboard
Webhooks
API Documentation

The API documentation is generated using Scribe.

After generating documentation:

php artisan scribe:generate

Access:

http://127.0.0.1:8000/docs

Documentation includes:

API endpoints
Request parameters
Response examples
Authentication information
Postman Collection
OpenAPI specification
API Routes

All API routes are versioned under:

/api/v1
Public Routes
POST /api/v1/register

POST /api/v1/login

POST /api/v1/stripe/webhook

GET /api/v1/payment/success

GET /api/v1/payment/cancel
Authenticated Routes
POST /api/v1/logout

Requires:

Authorization: Bearer {token}
Customer Routes

Protected by:

auth:sanctum
role:customer
Spaces
GET    /api/v1/customer/spaces

GET    /api/v1/customer/spaces/{id}

GET    /api/v1/customer/spaces/{id}/availability

GET    /api/v1/customer/spaces/{id}/slots
Bookings
POST   /api/v1/customer/bookings

GET    /api/v1/customer/bookings

GET    /api/v1/customer/bookings/{id}

GET    /api/v1/customer/bookings/{id}/status

PUT    /api/v1/customer/bookings/{id}

PATCH  /api/v1/customer/bookings/{id}/cancel
Payments
POST /api/v1/customer/payments/checkout
Notifications
GET  /api/v1/customer/notifications

POST /api/v1/customer/notifications/{notification}/read

POST /api/v1/customer/notifications/read-all
Staff Routes

Protected by:

auth:sanctum
role:staff
Booking Approval
GET   /api/v1/staff/bookings

PATCH /api/v1/staff/bookings/{id}/approve

PATCH /api/v1/staff/bookings/{id}/reject
Space Management
GET    /api/v1/staff/spaces

POST   /api/v1/staff/spaces

PUT    /api/v1/staff/spaces/{id}

DELETE /api/v1/staff/spaces/{id}

Media:

POST   /api/v1/staff/spaces/{space}/upload-image

POST   /api/v1/staff/spaces/{space}/upload-images

DELETE /api/v1/staff/media/{id}

POST   /api/v1/staff/media/{id}/set-main
Admin Routes

Protected by:

auth:sanctum
role:admin
Dashboard & Reports
GET /api/v1/admin/dashboard

GET /api/v1/admin/reports/summary
Users
GET    /api/v1/admin/users

PATCH  /api/v1/admin/users/{id}/role

DELETE /api/v1/admin/users/{id}
Categories
GET    /api/v1/admin/categories

POST   /api/v1/admin/categories

PUT    /api/v1/admin/categories/{id}

DELETE /api/v1/admin/categories/{id}
Project Architecture

The project follows a layered backend architecture:

Routes

   ↓

Controllers

   ↓

Services

   ↓

Models

   ↓

Database

Event-driven components:

Events

↓

Listeners

↓

Notifications

↓

Queue
Project Structure
app/
 ├── Http/Controllers
 ├── Models
 ├── Services
 ├── Events
 ├── Listeners
 ├── Notifications
 ├── Policies

database/
 └── migrations

routes/
 └── api.php

tests/
 └── Feature
Environment Variables

Required environment variables:

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
Future Improvements

Possible enhancements:

Increase automated test coverage
Add Redis caching
Add real-time notifications using WebSockets
Add CI/CD pipeline
Docker deployment
Advanced analytics dashboard
License

MIT

Author
Nevin Shabat

Laravel Backend Developer

Nivin Shabat

Laravel Backend Developer
