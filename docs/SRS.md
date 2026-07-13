# Software Requirements Specification (SRS)

## 1. Introduction

### 1.1 Purpose

This SRS describes the requirements for the `Room Booking System API`, a Laravel-based backend that enables customers, staff, and administrators to manage room/space reservations, payments, and reporting.

### 1.2 Scope

The system provides the following main capabilities:
- User authentication and authorization
- Role-based access control for customers, staff, and admin users
- Space and category management
- Availability checking and visible booking slots
- Booking creation, update, cancellation, approval, and rejection
- Stripe payment checkout and webhook handling
- Notifications via email and database
- Admin dashboard and reporting

### 1.3 Definitions, Acronyms, and Abbreviations

- API: Application Programming Interface
- SRS: Software Requirements Specification
- UI: User Interface
- Stripe: Third-party payment gateway used for checkout and webhook processing
- Booking: Reservation of a space for a defined time interval
- Slot: An hourly reservation interval for a space
- Customer: A user who books spaces
- Staff: A user who manages spaces and approves bookings
- Admin: A user who manages categories, users, and reports

## 2. Overall Description

### 2.1 Product Perspective

The application is a backend API designed to be consumed by web or mobile clients. It exposes REST endpoints under `/api/v1` and separates domain concerns into controllers, services, models, events, and notifications.

### 2.2 Product Functions

The system functions include:
- Registering and logging in users
- Managing user roles and profiles
- Listing and filtering spaces
- Managing space categories and media assets
- Displaying availability and hourly booking slots
- Creating and modifying bookings
- Processing Stripe payments and handling webhook events
- Approving or rejecting bookings by staff
- Generating admin reports and dashboard metrics
- Sending notifications for booking and payment events

### 2.3 User Classes and Characteristics

- Customer: needs a simple workflow to book available rooms and pay.
- Staff: needs tools to manage spaces and moderate bookings.
- Admin: needs oversight over users, categories, bookings, and business metrics.

### 2.4 Operating Environment

- Server OS: Windows or Linux supported by Laravel
- PHP 8.2+
- MySQL database
- Composer for PHP dependencies
- Node.js / npm for optional frontend asset compilation and Vite
- Stripe service for payments

### 2.5 Design and Implementation Constraints

- The system uses Laravel 12.
- Sanctum is used for API authentication.
- Stripe webhook secret must be configured in `.env`.
- Media files use Spatie Media Library and require a configured filesystem disk.
- Queue workers are recommended for asynchronous email and event processing.

### 2.6 Assumptions and Dependencies

- Clients supply valid API tokens and correct content types for requests.
- Stripe webhook verification depends on correct `services.stripe.webhook_secret` value.
- Administrators will configure categories and user roles through the admin API.
- Availability and booking slots are hourly increments between 08:00 and 23:00.

## 3. Functional Requirements

### 3.1 Authentication and Authorization

#### 3.1.1 Register User
- The system shall allow new users to register with `name`, `email`, and `password`.
- New users shall receive a Sanctum token.
- New users default to the `customer` role.

#### 3.1.2 Login User
- The system shall allow existing users to log in with `email` and `password`.
- The system shall return a user object and token for authenticated sessions.

#### 3.1.3 Logout User
- The system shall revoke all current tokens for the authenticated user.

#### 3.1.4 Role-Based Access
- The system shall restrict customer endpoints to users with role `customer`.
- The system shall restrict staff endpoints to users with role `staff`.
- The system shall restrict admin endpoints to users with role `admin`.

### 3.2 Space and Category Management

#### 3.2.1 List Spaces
- The system shall allow customers and staff to list spaces.
- The system shall support filtering by category, price range, capacity, and search term.

#### 3.2.2 Create Space
- The system shall allow staff users to create spaces with: title, description, location, price_per_hour, capacity, status, and categories.

#### 3.2.3 Update Space
- The system shall allow authorized staff to update existing spaces.

#### 3.2.4 Delete Space
- The system shall allow authorized staff to delete spaces.

#### 3.2.5 Category CRUD
- The system shall allow admin users to create, update, list, and delete categories.

### 3.3 Availability and Slots

#### 3.3.1 Get Availability
- The system shall provide availability data for a space on a target date.

#### 3.3.2 Get Slots
- The system shall provide hourly slots for a space between 08:00 and 23:00.
- The system shall mark each slot as available or unavailable based on existing bookings.

### 3.4 Booking Management

#### 3.4.1 Create Booking
- The system shall allow a customer to create a booking for a selected available slot.
- The system shall validate space availability before creating the booking.
- The system shall set the booking status to `pending_payment` and calculate the total_amount.

#### 3.4.2 View Bookings
- The system shall allow customers to view their bookings.
- The system shall allow staff and admin to view bookings relevant to their permissions.

#### 3.4.3 Update Booking
- The system shall allow customers to update bookings that are not `confirmed` or `cancelled` and are still future bookings.

#### 3.4.4 Cancel Booking
- The system shall allow customers to cancel pending or eligible bookings.
- The system shall disallow cancellation of past or ongoing bookings.
- Confirmed bookings shall require refund handling.

#### 3.4.5 Approve Booking
- The system shall allow staff to approve bookings in `pending_staff_approval`.
- Approved bookings shall change status to `confirmed` and record approver details.

#### 3.4.6 Reject Booking
- The system shall allow staff to reject bookings in `pending_staff_approval`.
- If payment exists, the system shall issue a refund through Stripe and mark the booking `rejected`.

### 3.5 Payment Processing

#### 3.5.1 Checkout Session
- The system shall allow customers to initiate Stripe checkout for bookings in status `pending_payment`.
- The system shall return a checkout URL for the client.

#### 3.5.2 Stripe Webhook Handling
- The system shall verify webhook signatures using Stripe's secret.
- The system shall log webhook events to prevent double processing.
- The system shall handle successful payments, failed payments, and refund events.
- On successful payment, the booking status shall transition to `pending_staff_approval`.
- On failed payment, the booking status shall transition to `payment_failed`.
- On refund events, the payment shall be marked refunded and the booking shall be updated accordingly.

### 3.6 Notifications

#### 3.6.1 Booking Notifications
- The system shall emit booking events and send notifications through mail and database.

#### 3.6.2 User Notifications
- The system shall allow users to fetch notifications and mark them read individually or all at once.

### 3.7 Admin Reporting

#### 3.7.1 Dashboard Metrics
- The system shall expose admin dashboard data with summary metrics.

#### 3.7.2 Report Summary
- The system shall expose a report summary endpoint for admin insights.

### 3.8 Media Management

#### 3.8.1 Upload Images
- The system shall allow staff to upload one or more images for a space.

#### 3.8.2 Delete Images
- The system shall allow authorized users to delete uploaded media.

#### 3.8.3 Set Main Image
- The system shall allow authorized users to mark an image as main.

## 4. Non-Functional Requirements

### 4.1 Performance

- The API should return responses quickly for list and detail queries.
- Booking availability checks should complete within acceptable response time.

### 4.2 Security

- All authenticated APIs shall use Sanctum token authentication.
- Role middleware shall prevent unauthorized access.
- Stripe webhook signature validation must be enforced.
- Sensitive fields such as passwords must be hashed.

### 4.3 Reliability

- Stripe webhook processing shall be idempotent.
- Booking status transitions shall be validated.

### 4.4 Maintainability

- Business rules shall live in service classes.
- Status transition logic shall be centralized in a status service.

### 4.5 Usability

- API responses shall return JSON with clear `success` and `message` fields.
- Validation errors shall return appropriate HTTP status codes.

### 4.6 Compatibility

- The API shall support MySQL and PHP 8.2+.
- The API shall support API clients on web and mobile platforms.

## 5. System Models and Data Requirements

### 5.1 User
- Must store `name`, `email`, `password`, `role`.
- Must support role assignment by admin.

### 5.2 Space
- Must store title, description, location, price_per_hour, capacity, status, and created_by.
- Must link to categories and media.

### 5.3 Category
- Must store category names and relate to spaces.

### 5.4 Booking
- Must store user_id, space_id, start_time, end_time, total_amount, currency, status, and timestamps.
- Must support status transitions and expiration logic.

### 5.5 Payment
- Must store provider details, payment_intent_id, status, amount, currency, and timestamps.
- Must relate to a booking and payment transactions.

## 6. Assumptions and Constraints

- Bookings are hourly slots only.
- Space availability is determined by checking overlapping bookings in active statuses.
- Stripe is the designated payment gateway.
- Images are stored through Spatie Media Library on a configured disk.
- Email delivery depends on configured mail driver and SendGrid credentials.

## 7. Future Enhancements

- Add configurable business hours and blackout dates.
- Implement recurring bookings.
- Support partial refunds and disputes.
- Add real-time availability updates and calendar integration.
- Add SMS notifications.
- Add richer admin analytics and exportable reports.
- Add robust feature tests for full booking and payment flows.

## 8. Revision History

- **2026-07-12**: Created initial SRS from current codebase and system behavior.
