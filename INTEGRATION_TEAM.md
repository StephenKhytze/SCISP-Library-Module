# SCISP LIBRARY — INTEGRATION TEAM HANDOFF

## 1. Current Module Status

- Library module stable
- Docker-first
- React/Vite frontend
- Laravel backend
- REST API
- Eloquent ORM
- MySQL
- cleaned migration baseline
- Library tests baseline:
  439 passed / 1254 assertions
- 49 Library routes

## 2. Current Architecture

React + Vite
↓
Library Axios client
↓
REST API
↓
Laravel
↓
Eloquent ORM
↓
MySQL

The Library currently uses a mock-auth Axios client during QA.

## 3. Frontend Ownership

Library-specific:
rontend/src/modules/library/

Important structure:
- LibraryPortal.jsx
- components/
- hooks/
- services/

Shared/core files that should NOT be overwritten blindly:
- rontend/src/components/Layout.jsx
- rontend/src/components/Sidebar.jsx
- rontend/src/components/Topbar.jsx
- rontend/src/services/api.js

Explain:
rontend/src/modules/library/services/api.js = current Library mock-auth client
rontend/src/services/api.js = shared JWT/Auth client

## 4. Backend Ownership

Library controllers:
ackend/app/Http/Controllers/Api/Library/

Library routes:
ackend/routes/library.php

Main route loader:
ackend/routes/api.php

Library services remain under:
ackend/app/Services/

Models remain under:
ackend/app/Models/

Do not move/rename migrations during integration unless necessary.

## 5. API Contract

Current Library endpoints remain under:
/api/library/...

Integration must preserve these URLs unless coordinated across frontend/backend.

## 6. Authentication Handoff

CURRENT:
Topbar persona → X-Mock-Username → X-Mock-Role → MockAuthMiddleware

FINAL INTEGRATED TARGET:
JWT login → Bearer token → VerifyJwtToken → request user/role context → Library authorization

Important requirement:
The JWT/Auth integration must supply the Library with compatible user identity and role information.
Do not silently remove MockAuth until the JWT path is verified end-to-end.

## 7. Known Auth Integration Mismatch

Auth Module:

ole = superadmin

Library currently:

ole = administrator + is_super_admin flag

Also, the Library expects 	otal_fines on users.

The integration team must agree on one shared users schema and one Super Admin representation before merging.

## 8. Shared Users Table

User.php is shared/core.
Do not overwrite Library or Auth versions blindly.

Review:
- 
ole
- is_super_admin
- 	otal_fines
- status
- username
- password
- 	wo-factor fields

before merging migrations/models.

## 9. Migration Baseline

Library migration history was cleaned/squashed.
Current fresh setup requires:
migrate:fresh
for teammates coming from the old migration history.

Do not run the new migration filenames over the old local migration history.
New clean installs can use the clean baseline directly.

## 10. Docker

Current expected services:
- rontend
- ackend
- db

Command:
docker compose up -d --build

Ports:
- frontend: 5173
- backend: 8000
- MySQL: according to compose

Integration team should preserve or intentionally reconcile these.

## 11. Temporary QA Assets

- TemporaryQaUsersSeeder.php
- QA personas in Topbar

These are temporary development/testing aids.
They should be removed or disabled when the real Auth/account module is fully integrated.
Do NOT remove them before integration testing is complete.

## 12. Important Business Rules to Preserve

- Student vs Faculty borrowing limits
- Super Admin cannot borrow
- role-gated Admin features
- archive behavior
- hold FCFS
- reserve copy protection
- course section authorization
- renewal rules
- fine rules
- archived titles hidden from borrowers

Business rules are enforced by backend tests and must not be bypassed during merge.

## 13. Recent Regression Fixes to Preserve

- Register Title modal wiring
- Classmates modal prop wiring
- Course Reserve section selector restored
- searchable book picker
- Inventory category filter
- Settings discard performance fix
- frontend Docker service restored

These areas require regression testing after integration.

## 14. Known Remaining Issues / Risks

- Auth/Super Admin schema mismatch
- duplicate Settings fetches
- Register Title Escape/accessibility polish

## 15. Files Not to Overwrite Blindly

- Layout.jsx
- Sidebar.jsx
- Topbar.jsx
- User.php
- 
outes/api.php
- shared JWT services/api.js
- shared users migration
- docker-compose.yml

These require merge/reconciliation, not one-side replacement.

## 16. Integration Order

1. pull latest Library
2. reconcile shared users schema
3. reconcile role names
4. integrate JWT
5. replace/mock-auth path carefully
6. merge shared Topbar/Layout/Sidebar
7. preserve /api/library routes
8. migrate fresh in integration environment
9. seed temporary identities only if still needed
10. run complete regression

## 17. Required Post-Integration Regression

- fresh Docker startup
- migrations
- JWT login
- Student
- Faculty
- Admin
- Super Admin
- Catalog
- Inventory
- Register Title
- Circulation
- Holds
- Renewals
- Fines
- Sections
- Course Reserves
- Classmates
- Settings
- responsive
- console/network
- full backend tests

## 18. Current Known-Good Reference

branch: lyndon

## 19. Integration Sign-off Checklist

- [ ] users schema reconciled
- [ ] role representation reconciled
- [ ] JWT verified
- [ ] mock auth removed/disabled only after replacement works
- [ ] Topbar/Sidebar/Layout merged
- [ ] API URLs preserved
- [ ] migrations run from clean DB
- [ ] Library tests pass
- [ ] browser smoke test passes
- [ ] no unexpected 401/403/404/500
- [ ] temporary QA artifacts reviewed
