# PolicyFlow

PolicyFlow is a Laravel REST API prototype for travel-insurance policy intake and issuance. It accepts a request, validates its trip and coverage data, stores a deterministic fictional premium, and tracks issuance through a local mock insurer. Its reliability focus is safe duplicate intake, retry handling, timeout reconciliation, and idempotent callback processing.

This is a student assignment prototype, not a production insurance platform. It uses fabricated sample data and does not connect to real insurers, payment gateways, or external paid services.

## Technology

- PHP 8.2 or newer
- Laravel 12 (satisfies the assignment's Laravel 11+ requirement)
- MySQL 8+ for the documented local application setup
- Composer
- Node.js and npm (to build the dashboard assets)
- PHP `pdo_mysql` and other Laravel-required extensions
- SQLite PDO extension for the test command below

## Local installation

Run these commands from the project root in PowerShell:

```powershell
composer install
Copy-Item .env.example .env
npm install
```

Create a local MySQL database (for example, in a MySQL client):

```sql
CREATE DATABASE policyflow_local
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

Edit `.env` locally and set `DB_USERNAME` and `DB_PASSWORD` for your local MySQL account. These values are intentionally blank in `.env.example`; do not commit `.env` or credentials. Then generate the application key and apply migrations:

```powershell
php artisan key:generate
php artisan migrate
npm run build
```

Start the web application:

```powershell
php artisan serve
```

The PolicyFlow dashboard is available at `http://127.0.0.1:8000`; the REST API is available under `http://127.0.0.1:8000/api`.

## Database design

All application tables are created through Laravel migrations. The initial Laravel migrations also create users, cache/session, and database-queue support tables.

| Table | Purpose |
|---|---|
| `policy_requests` | Accepted request, current state, and immutable premium/pricing snapshot. A database unique constraint on `(agent_id, client_request_id)` prevents a second logical request. |
| `mock_insurer_records` | Durable mock provider status, scenario, provider reference, response, and issued result used for timeout reconciliation. |
| `issuance_attempts` | Submission attempt number, outcome, provider response/error, and timestamps. |
| `issued_policies` | Confirmed policy number and provider reference. Unique constraints allow at most one issued policy per request and prevent policy-number reuse. |
| `provider_events` | Callback payload and processing result. The unique provider event ID makes repeated deliveries idempotent. |
| `policy_state_history` | Append-only record of state transitions and their source/reason. |

The request has many attempts, provider events, and history entries. Its mock-insurer record and issued policy are one-to-one relationships. Foreign keys connect these records to the original request.

## API

All endpoints are JSON REST endpoints.

| Method | Endpoint | Purpose | Normal response |
|---|---|---|---|
| `POST` | `/api/policy-requests` | Validate, price, and create a request; return the original on identical retry | `201` new, `200` identical retry |
| `GET` | `/api/policy-requests/{id}` | Retrieve request, premium, references if present, and audit trail | `200`; `404` when absent |
| `GET` | `/api/policy-requests` | List requests; optional `status` and `per_page` | `200` |
| `POST` | `/api/policy-requests/{id}/submit` | Submit or safely reconcile issuance | `200` |
| `POST` | `/api/mock-insurer/callback` | Receive a simulated provider event | `200` |

Validation failures return `422`. Reusing an intake key with different request data returns `409`. An unknown provider reference returns `404`; reusing an event ID for different callback data returns `409`. The list endpoint accepts the defined state names as `status`; `per_page` is an integer from 1 to 100.

### Create a request

```powershell
curl.exe -X POST http://127.0.0.1:8000/api/policy-requests `
  -H "Accept: application/json" `
  -H "Content-Type: application/json" `
  -d '{"client_request_id":"AGT-2026-0001","agent_id":"AGENT-17","customer_name":"Sample Customer","date_of_birth":"1998-04-10","destination":"INTERNATIONAL","trip_start":"2026-11-10","trip_end":"2026-11-14","plan_code":"PLUS","sum_insured":300000}'
```

Dates use `YYYY-MM-DD`. The response is a Laravel JSON resource under `data`, including the generated numeric `id`, UUID, `premium`, `pricing_breakdown`, and initial `RECEIVED` state. An identical submission returns the same request and premium with `200`; if any request field differs for the same agent/request key, the API returns `409`.

### Retrieve, list, and submit

```powershell
curl.exe -H "Accept: application/json" http://127.0.0.1:8000/api/policy-requests/1
curl.exe -H "Accept: application/json" "http://127.0.0.1:8000/api/policy-requests?status=RECEIVED&per_page=10"
curl.exe -X POST -H "Accept: application/json" http://127.0.0.1:8000/api/policy-requests/1/submit
```

For local scenario testing, pass one of the supported scenario values to the submit endpoint:

```powershell
curl.exe -X POST http://127.0.0.1:8000/api/policy-requests/1/submit `
  -H "Accept: application/json" `
  -H "Content-Type: application/json" `
  -d '{"scenario":"TIMEOUT_AFTER_ISSUE"}'
```

The scenario selector is permitted only in the `local` and `testing` environments. If omitted, the default mock scenario is `SUCCESS`. All mock scenarios are fictional and local.

## Pricing rules

The calculator uses the assignment's fictional formula:

```text
premium = base daily rate × inclusive travel days
          × age multiplier × destination multiplier × coverage multiplier
```

- Base daily rate: `BASIC` ₹12, `PLUS` ₹20, `PREMIUM` ₹30.
- Age on `trip_start`: 0–17 = 1.10; 18–60 = 1.00; 61–70 = 1.50; 71+ = 2.00.
- Destination: `DOMESTIC` = 1.00; `INTERNATIONAL` = 1.80.
- Sum insured: ₹100,000 = 1.00; ₹300,000 = 1.25; ₹500,000 = 1.50.
- Travel days are `trip_end - trip_start + 1`, with a minimum of one day.
- The calculator uses integer-scaled multipliers and integer currency arithmetic, rounds the result to paise (two decimal places), and stores the premium and calculation breakdown at intake. Retries retain that snapshot.

The example request above has five travel days and calculates to **₹225.00**.

## Issuance states and reliability

The supported states are `RECEIVED`, `VALIDATED`, `SUBMITTED`, `PENDING_CONFIRMATION`, `ISSUED`, `FAILED`, and `NEEDS_RECONCILIATION`.

The explicit transition map permits:

```text
RECEIVED -> VALIDATED
VALIDATED -> SUBMITTED
SUBMITTED -> PENDING_CONFIRMATION | ISSUED | FAILED | NEEDS_RECONCILIATION
PENDING_CONFIRMATION -> ISSUED | FAILED | NEEDS_RECONCILIATION
FAILED -> VALIDATED
NEEDS_RECONCILIATION -> ISSUED | FAILED | PENDING_CONFIRMATION
ISSUED -> (no transitions; terminal)
```

Each transition is written to `policy_state_history`. Database uniqueness constraints protect the intake key, provider event ID, and issued policy. A repeated submit of a `SUBMITTED` or `PENDING_CONFIRMATION` request checks the mock provider status before any new provider submission. The `TIMEOUT_AFTER_ISSUE` mock stores its policy before simulating a timeout; reconciliation then discovers the saved result rather than issuing again.

Supported development/test scenarios are `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, and `DUPLICATE_CALLBACK`. The delayed scenario first returns `PENDING_CONFIRMATION`, then queues a job that generates a provider event. The duplicate scenario delivers the same event more than once to exercise idempotency.

### Run the database queue worker

The delayed callback uses Laravel's database queue; no external queue service is needed. Keep the application server running and start a worker in another terminal:

```powershell
php artisan queue:work --queue=default
```

The queued callback is delayed briefly, so the submit endpoint can return the pending response first. The worker must remain running to deliver it. Stop the local worker with `Ctrl+C` when finished.

## Tests and formatting

The feature tests cover the pricing example, date/plan/coverage validation, duplicate and conflicting intake, request retrieval and listing, successful issuance, temporary-failure retry, timeout reconciliation, duplicate submissions/callbacks, delayed callback processing, database uniqueness, and valid/invalid state transitions.

The suite has been run against in-memory SQLite, not MySQL. From PowerShell:

```powershell
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
php artisan test
```

The test environment overrides the MySQL primary development setup; tests run after creating their schema through Laravel's test migrations. A database uniqueness test attempts a second insert with the same key, but this is **not** a true simultaneous-request/concurrency test. MySQL behavior and true concurrent intake have not yet been verified.

Run Laravel Pint's non-modifying check with:

```powershell
vendor\bin\pint --test
```

## Known limitations

- MySQL 8+ setup is documented, but MySQL migrations and the test suite have not yet been verified against a running MySQL server.
- Concurrent intake has not been load-tested or tested with simultaneous clients. The database unique constraint is the final duplicate protection.
- The delayed callback requires a running database queue worker.
- `NEEDS_RECONCILIATION` is part of the transition map, but the current workflow does not automatically move requests into that state.
- The mock insurer is an in-process local simulation, not a real provider integration.
- This API prototype does not implement authentication or a user interface.
