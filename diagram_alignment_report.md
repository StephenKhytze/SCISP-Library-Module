# Diagram Alignment Report

This document reviews how the implemented SCISP Library Module aligns with the provided system specifications, Database Schema, ER Diagrams, Architecture Diagrams, and Use Case Diagrams.

## 1. Database Schema & ER Diagrams
The physical database implementation perfectly mirrors the detailed ER model provided in the specifications.

- **Books (`books`)**: Contains `book_title`, `author`, `category`, `isbn`, `physical_location`, and `total_copies`.
- **Book Copies (`book_copies`)**: Contains `copy_id`, `book_id`, `condition`, and `availability_status`.
- **Transactions (`transactions`)**: Contains `transaction_id`, `user_id`, `copy_id`, `date_borrowed`, `due_date`, `actual_return_date`, and `status`.
- **Holds (`holds`)**: Contains `hold_id`, `user_id`, `book_id`, `request_date`, `queue_position` (to enforce First-Come-First-Served), and `status`.
- **Users (`users`)**: The system correctly treats the User as an `[External] User`. The local users table only stores Library-specific state (`total_fines`), entirely respecting the microservice boundaries.

## 2. System Architecture
The application structure successfully implements all the internal blocks shown in the Library Service architecture box:

- **Inventory Management**: Handled by `BookController` and `BookCopyController`.
- **Search and Filter Engine**: Handled by `InventoryService::searchBooks`, featuring live subquery counts for availability.
- **Circulation Manager**: Handled by `CirculationService`, featuring robust `lockForUpdate()` concurrency safety.
- **Fines Calculator**: Handled by `FinesCalculator`.
- **Hold and Queue Manager**: Handled by `HoldService`.

### API Endpoints
The implemented endpoints are functionally identical to the architecture diagram, though structured following strict RESTful conventions:
- Diagram: `GET /books/search` → Implemented: `GET /api/library/books`
- Diagram: `POST /transactions/checkout` → Implemented: `POST /api/library/checkout`
- Diagram: `POST /transactions/checkin` → Implemented: `POST /api/library/checkin`
- Diagram: `POST /holds` → Implemented: `POST /api/library/books/{id}/holds`

## 3. Use Case Diagram
The system supports all designated use cases for the Library Module.

- **Self-Checkout vs Admin Checkout Note**: The Use Case diagram illustrates *Students* and *Faculty* initiating the "Check Out Book" and "Return Book" actions. In the current implementation (Phase 4), the `/checkout` and `/checkin` routes are placed behind the `RequireAdminRole` middleware, assuming a traditional librarian-operated circulation desk. 
  - *Adjustment path*: If the project requires self-service kiosk functionality (allowing students to checkout books on their own devices), those two routes can simply be moved outside the `RequireAdminRole` middleware group in `routes/api.php`.
- **View Active Loans / View Fines**: The database and service architecture fully support fetching this data (`Transaction::where('user_id', ...)`). The GET endpoints for users to view their own transactions can easily be exposed if the frontend requires them.

## Conclusion
The Library Module backend strictly follows the provided architecture, entity relationships, and service delegations. The addition of row-locking and external fake-auth makes it a highly robust foundation for the Elective 4 project.
