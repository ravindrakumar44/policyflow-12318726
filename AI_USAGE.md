# AI usage disclosure

> Candidate: review and update this disclosure before submission so it accurately reflects your own tool use, decisions, and review.

## Tools used

- Confirmed for this coding session: an AI assistant using Copilot SDK in VS Code.
- Other AI tools, if any: **[Candidate: list tools you personally used, or write “None.”]**

## How AI assisted

During this coding session, the assistant helped with:

1. Mapping assignment requirements to implementation steps and reviewing schema/API choices.
2. Drafting parts of the Laravel implementation and automated tests for intake, pricing, issuance, and reliability.
3. Reviewing project files and preparing project documentation.

**Candidate confirmation:** Please confirm these points describe the assistance you received and add any other material use.

## Incorrect or incomplete AI suggestion

An earlier version of the delayed-callback scenario returned a pending response but depended on a manually supplied callback to complete issuance. Review identified that this did not meet the scenario's delayed-delivery requirement. The implementation was subsequently changed to queue a callback job and test its delivery.

**Candidate reflection:** **[Candidate: add any other incorrect or incomplete suggestion you encountered, or state that this is the only one you identified.]**

## Verification performed

The implementation was checked with the project test suite and Laravel Pint. The feature tests exercise pricing, input validation, duplicate intake, issuance outcomes, timeout reconciliation, callbacks, queued delayed callback delivery, and state transitions.

- Test command: `php artisan test` with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.
- Formatting command: `vendor\bin\pint --test`.
- The recorded local test database is in-memory SQLite. MySQL 8+ and true concurrent-request behavior have not been verified.

**Candidate review:** **[Candidate: describe any additional manual review or verification you personally performed before submitting.]**

No private chat transcripts, credentials, or account details belong in this disclosure.
