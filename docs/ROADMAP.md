# Room Booking System Roadmap

This roadmap maps the original project requirements to the current implementation and the next recommended development steps.

## Requirement Coverage

| Requirement | Status | Notes |
| --- | --- | --- |
| User registration and login | Done | Implemented with Laravel Sanctum. |
| User roles and permissions | Partial | Role middleware exists. Policies exist but need more consistent use before state changes. |
| Space/room CRUD | Done | Staff can manage spaces. |
| Tagging and categorization | Done | Categories and pivot table are implemented. |
| Price configuration | Done | Spaces have hourly pricing and pricing service calculates booking amount. |
| Availability configuration | Partial | Availability and slots exist, but working hours and rules should be configurable. |
| Search and filter spaces | Done | Search, category, price, capacity, and sorting exist. |
| Booking creation | Done | Customers can create bookings for available slots. |
| Booking modification | Partial | Customers can update bookings, but lifecycle rules should be tightened. |
| Booking cancellation | Partial | Customers can cancel some bookings. Refund-based cancellation needs completion. |
| Real-time availability updates | Not Done | Current system is API-based. Add websockets or polling strategy later. |
| Email notifications | Partial/Done | Mail classes, events, and listeners exist. Needs testing and queue verification. |
| SMS notifications | Not Done | Add provider such as Twilio if required. |
| Booking reminders | Partial | Command/job structure exists. Needs end-to-end verification. |
| Reporting and analytics | Partial | Basic dashboard exists. Needs richer reports and more accurate revenue metrics. |
| Role-based access control | Partial | Middleware exists. Policies need stronger coverage. |
| Data encryption and privacy | Partial | Laravel defaults exist. Need rate limits, safe responses, and security review. |
| Mobile responsiveness | API Scope | Backend supports mobile clients. Responsive UI belongs to frontend. |
| Scalability | Partial | Queues/events exist. Need pagination, caching, tests, indexes review, and concurrency hardening. |
| Payment gateway integration | Partial/Done | Stripe checkout and webhooks exist. Needs webhook idempotency and test coverage. |
| Disputes and refunds | Partial | Refund concepts exist. Disputes and robust refund lifecycle are not complete. |

## What Has Been Achieved

- Core Laravel API structure is in place.
- Authentication and roles are implemented.
- Main actors are separated through route groups: customer, staff, and admin.
- Space management supports categories, filters, and media uploads.
- Booking creation, update, cancellation, approval, and rejection flows exist.
- Stripe Checkout integration exists.
- Stripe webhook signature verification exists.
- Event/listener structure exists for booking and payment side effects.
- Mail and notification classes exist.
- Basic dashboard statistics exist.
- Database schema includes bookings, payments, payment transactions, media, notifications, and webhook logs.

## Main Technical Risks

### 1. Double Booking Under Concurrency

The availability check prevents obvious conflicts, but it is not yet strong enough for simultaneous booking requests. A production-ready booking system should use locking, transactional checks, or database-level constraints/strategies that prevent two users from reserving the same room and time slot at the same time.

### 2. Authorization Before State Changes

Sensitive actions should authorize the user before updating data. For example, staff approval should confirm that the staff member is allowed to approve the booking before changing the booking status.

### 3. Payment and Refund Reliability

Payment systems need careful handling of repeated webhook events, failed payments, partial refunds, full refunds, and disputes. The current implementation has the right direction, but needs a stricter lifecycle and tests.

### 4. Webhook Idempotency

Stripe can send the same event more than once. The application should store processed webhook event IDs and ignore duplicates.

### 5. Minimal Automated Tests

The current test suite does not yet prove the main business flows. This is the biggest gap before calling the system production-ready.

## Recommended Next Phase: Production Hardening

### Step 1: Stabilize Booking and Payment Lifecycles

Define allowed status transitions in one place.

Recommended booking transitions:

```text
pending_payment -> pending_staff_approval
pending_payment -> payment_failed
pending_payment -> expired
pending_payment -> cancelled
pending_staff_approval -> confirmed
pending_staff_approval -> rejected
confirmed -> cancelled/refund_requested
```

Recommended payment transitions:

```text
pending -> paid
pending -> failed
paid -> refunded
paid -> partially_refunded
paid -> disputed
```

### Step 2: Fix Authorization

- Authorize staff approval before updating booking status.
- Authorize staff rejection before triggering refund logic.
- Re-enable authorization checks for media deletion.
- Review admin and staff route permissions.

### Step 3: Complete Refund Flow

- Fix refund service usage inside booking rejection.
- Store refund transaction records.
- Update payment status only after Stripe confirms the refund or after a clear local refund request state.
- Add clear behavior for confirmed booking cancellation.

### Step 4: Add Webhook Idempotency

- Store Stripe event ID, event type, payload, and processing status in `webhook_logs`.
- Ignore events that were already processed.
- Log failed processing attempts for debugging.

### Step 5: Strengthen Availability

- Re-check availability inside a database transaction.
- Lock relevant rows or use a consistent booking conflict prevention strategy.
- Decide whether pending unpaid bookings reserve a slot and for how long.
- Add cleanup for expired `pending_payment` bookings.

### Step 6: Add Feature Tests

Highest-priority tests:

- Customer can register and log in.
- Customer can create a booking for an available slot.
- Customer cannot create a booking for an unavailable slot.
- Customer cannot pay another user's booking.
- Staff cannot approve a booking for a space they do not own/manage.
- Staff can approve an eligible booking.
- Staff can reject an eligible booking and trigger refund logic.
- Stripe checkout completed webhook updates payment and booking.
- Duplicate Stripe webhook event does not apply changes twice.
- Admin dashboard returns expected statistics.

### Step 7: Improve Reporting

Recommended metrics:

- Confirmed bookings count.
- Paid revenue only.
- Revenue by day/month.
- Occupancy rate per space.
- Top spaces by bookings and revenue.
- Cancellation rate.
- Refund rate.
- Pending approval count.

### Step 8: API Documentation

Use Scribe or the existing Postman collection to document:

- Authentication flow.
- Required headers.
- Roles and permissions.
- Customer endpoints.
- Staff endpoints.
- Admin endpoints.
- Stripe webhook behavior.
- Booking lifecycle.
- Payment lifecycle.

## Advanced Enhancements

These should come after hardening the current system:

- Real-time availability updates using Laravel Reverb, Pusher, or a polling strategy.
- SMS notifications using Twilio or another SMS provider.
- Export reports as CSV/PDF.
- Admin audit logs.
- Rate limiting per endpoint group.
- Soft deletes for important records.
- Configurable business hours per space.
- Blackout dates and maintenance periods.
- Recurring bookings.
- Coupons or discounts.
- Partial refunds.
- Stripe disputes/chargebacks.
- Multi-location facility support.
- Calendar integration.

## Suggested Learning Focus

This project is a strong opportunity to practice backend topics that matter in real systems:

- Laravel feature testing.
- Transactions and race conditions.
- Authorization policies.
- Queues and event-driven workflows.
- Webhook reliability.
- Payment lifecycle design.
- API documentation.
- Clean service-layer boundaries.
