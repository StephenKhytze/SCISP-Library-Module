# SCISP Library Module - Development Changelog

This document summarizes the development progress and commits made during the implementation of the Library Module. The module was developed in 5 distinct phases to ensure a clean, decoupled, and robust architecture.

## Phase 1: Foundation (Database & Models)
- **Migrations**: Created migrations for all core entities: `books`, `book_copies`, `transactions`, and `holds`.
- **Users Table Adaptation**: Modified the existing `users` table template to only track library-specific state (`total_fines`), acknowledging that the primary source of truth for user data resides in the external Authentication Module.
- **Eloquent Models**: Created `Book`, `BookCopy`, `Transaction`, `Hold`, and updated `User`. Established all relationships (e.g., `User` hasMany `transactions`, `Book` hasMany `copies`).

## Phase 2: Integration & Decoupling
- **Service Contracts**: Defined `AuthServiceInterface` and `ProfileServiceInterface` to ensure the Library Module never directly depends on the concrete implementation of external modules.
- **Fake Implementations**: Created `FakeAuthService` and `FakeProfileService` for local development.
- **Service Provider**: Created `ExternalModuleServiceProvider` to dynamically bind the interfaces to either Fake or Live implementations based on `.env` variables.
- **Authentication Middleware**: Developed `ExternalAuthMiddleware` which intercepts requests, validates the JWT via the Auth Service, provisions local lightweight user records via `firstOrCreate`, and injects the user's role into the request context.

## Phase 3: Search & Inventory
- **Inventory Service**: Created `InventoryService` to encapsulate catalog logic. Implemented a dynamic subquery (`withCount`) to calculate `available_copies_count` live from the `book_copies` table, completely eliminating the risk of cached `total_copies` drifting out of sync.
- **Role-Based Access Control**: Created `RequireAdminRole` middleware to protect inventory and circulation endpoints.
- **Controllers**: Implemented `BookController` (public search, admin create/edit) and `BookCopyController` (admin add physical copies, update copy condition).

## Phase 4: Circulation Management
- **Concurrency Safety**: Implemented the checkout process inside a `DB::transaction()` using pessimistic row locking (`lockForUpdate()`) on the `book_copies` table. This mathematically prevents race conditions if two users attempt to check out the exact same final copy simultaneously.
- **Dynamic Due Dates**: Integrated role-based due date calculations (`student`: 7 days, `faculty`: 14 days, `admin`: 30 days) into the checkout logic.
- **Fines Calculator**: Extracted fine logic into a dedicated `FinesCalculator` service. During check-in, the system dynamically calculates fines for overdue books and increments the user's `total_fines` balance.

## Phase 5: Automated Hold Queue
- **Hold Service**: Implemented `HoldService` to manage reservations. Holds can only be placed when `0` copies are available. Used transaction locking to safely generate sequential `queue_position` numbers.
- **Check-in Interception**: Modified the `CirculationService::checkin()` process. When a book is returned, it now checks the `HoldService` first. If a user is waiting, the physical copy is instantly marked as `on_hold` and assigned to the first person in the queue, bypassing general availability.
- **Hold Controllers**: Added endpoints for users to place and cancel their own holds.
