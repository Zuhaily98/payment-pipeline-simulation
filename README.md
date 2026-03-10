# Payment Pipeline Simulation

A command-line app built with Laravel that simulates a payment processing pipeline. It reads commands from a file or stdin and processes payments through their lifecycle (create, authorize, capture, settle, refund, void).

---

## How to Build and Run

### Requirements

- PHP 8.2 or higher
- Composer

### Installation

```bash
cd payment-pipeline

composer install

cp .env.example .env
php artisan key:generate
```

### Running the App

```bash
# read commands from a file
php artisan payment:pipeline --file=input.txt

# interactive mode (type commands one by one, or pipe input)
php artisan payment:pipeline
```

A sample input file (`input.txt`) is included.

### Running the Tests

```bash
# run all tests
php artisan test

# run only parser tests
php artisan test --filter=ParserTest

# run only pipeline integration tests
php artisan test --filter=PaymentPipelineTest
```

## Design

### How It Works

The app reads input line by line. Each line goes through three steps:

1. **Parse** — split the line into tokens, handle the `#` comment rules, check argument counts
2. **Route** — use `match()` to call the right handler method
3. **Handle** — each command has its own method that checks the transition, updates the payment, returns output

### State Transitions

All transitions are in a single array at the top of the command class:

```
AUTHORIZE: INITIATED → AUTHORIZED
CAPTURE:   AUTHORIZED → CAPTURED, PRE_SETTLEMENT_REVIEW → CAPTURED
VOID:      INITIATED → VOIDED, AUTHORIZED → VOIDED
REFUND:    CAPTURED → REFUNDED
SETTLE:    CAPTURED → SETTLED, SETTLED → SETTLED (idempotent)
```

Adding a new transition (e.g. `CHARGEBACK: CAPTURED → CHARGEBACKED`) means adding one line to this array and writing a handler method.

### Storage

Payments are stored in a PHP array keyed by `payment_id` for O(1) lookups. Works fine for thousands of commands. Settlement batches are tracked in a separate array.

### Error Handling

- Bad input (unknown commands, wrong number of args, bad amounts) → clear error message, then continues to the next line
- Invalid state transitions (e.g. refund before capture) → clear error, payment state stays unchanged
- Unexpected internal errors → caught by a `\Throwable` catch block, shows a generic message, never a stack trace

---

## Assumptions

### PRE_SETTLEMENT_REVIEW

If the payment amount is >= 1000.00, it gets routed to PRE_SETTLEMENT_REVIEW after authorization. Payments in this state can still be captured normally. Amount-based threshold is configurable via `PAYMENT_REVIEW_THRESHOLD` env variable so we can change it without editing code. Set it to 0 to disable review entirely.

### Partial Refunds

I chose to support the partial refunds:

- `REFUND P1001 30.00` on a 100.00 payment → records 30.00 refund, stays in CAPTURED (70.00 remaining)
- `REFUND P1001 70.00` later → now fully refunded, moves to REFUNDED
- `REFUND P1001` without an amount → full refund, moves to REFUNDED immediately
- Can't refund more than the remaining balance

This is controlled by config. Set `PAYMENT_PARTIAL_REFUNDS=false` to make any refund move straight to REFUNDED.

### SETTLE vs SETTLEMENT

- `SETTLE P1001` — per-payment operation, changes state from CAPTURED to SETTLED
- `SETTLEMENT BATCH001` — reporting operation, records the batch and prints a summary of settled payments, does NOT change any payment state

### Comment Parsing

`#` is only treated as a comment delimiter at token position 2 or later (0-indexed):

- `CREATE P1001 10.00 MYR M01 # note` → `#` at index 5 → stripped ✓
- `AUTHORIZE P1001 # retry` → `#` at index 2 → stripped ✓
- `# CREATE P1002 11.00 MYR M01` → `#` at index 0 → NOT a comment, malformed ✓

Also handles `#` in the middle of a token (e.g. `5.00#comment` → keeps `5.00`).

### AUDIT

Just prints "AUDIT RECEIVED" and does nothing else. No state changes, no side effects.

---

## Configuration

All settings are in `config/payment.php` and can be overridden with environment variables:

| Env Variable | Default | What it does |
|---|---|---|
| `PAYMENT_CURRENCIES` | `MYR,USD,EUR,GBP,SGD,JPY` | Accepted currency codes (comma-separated) |
| `PAYMENT_REVIEW_THRESHOLD` | `1000.00` | Amount threshold for PRE_SETTLEMENT_REVIEW (0 = disabled) |
| `PAYMENT_PARTIAL_REFUNDS` | `true` | Track partial refund amounts |

You can set these in your `.env` file:

```
PAYMENT_REVIEW_THRESHOLD=500.00
PAYMENT_CURRENCIES=MYR,USD,EUR,GBP,SGD,JPY,AUD
PAYMENT_PARTIAL_REFUNDS=true
```

---

## What I Would Do Differently for Production

- **Database** — use a real database instead of in-memory arrays so data persists between runs
- **Events** — fire events on state changes (PaymentCaptured, PaymentRefunded) so other systems can react
- **Logging** — use structured logging (JSON) instead of console output, for easier debugging in production
- **Audit trail** — store every state change in a separate database table with timestamps and who triggered it
- **API** — expose the same logic through REST endpoints, not just CLI
- **Queue** — use Laravel queues for batch settlement processing

---

## Test Coverage

| Requirement | Test |
|---|---|
| Happy path: CREATE → AUTHORIZE → CAPTURE → SETTLE → STATUS | `test_happy_path_create_authorize_capture_settle_status` |
| Happy path: CREATE → AUTHORIZE → VOID | `test_happy_path_create_authorize_void` |
| Happy path: CREATE → AUTHORIZE → CAPTURE → REFUND | `test_happy_path_create_authorize_capture_refund` |
| Invalid: REFUND before CAPTURE | `test_invalid_refund_before_capture` |
| Invalid: CAPTURE before AUTHORIZE | `test_invalid_capture_before_authorize` |
| Invalid: VOID after CAPTURE | `test_invalid_void_after_capture` |
| Idempotent: CREATE with same attributes | `test_idempotent_create_same_attributes` |
| Idempotent: SETTLE on settled payment | `test_idempotent_settle_already_settled` |
| Parser: inline comments stripped | `test_parser_inline_comment_stripped` + `test_comment_after_create_args_is_stripped` |
| Parser: # at start is malformed | `test_parser_hash_at_start_is_malformed` + `test_hash_at_start_of_line_is_not_a_comment` |
| AUDIT no side effects | `test_audit_no_side_effects` |
| SETTLE vs SETTLEMENT | `test_settle_vs_settlement` |
| PRE_SETTLEMENT_REVIEW | `test_pre_settlement_review_triggered` + `test_capture_from_pre_settlement_review` |
| Partial refund | `test_partial_refund_flow` |
| Error recovery | `test_parser_bad_input_continues_processing` |