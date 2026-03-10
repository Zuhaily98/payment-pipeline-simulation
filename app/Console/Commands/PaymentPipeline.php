<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;

class PaymentPipeline extends Command
{
    protected $signature = 'payment:pipeline {--file= : Path to input file}';
    protected $description = 'Run the payment pipeline simulation';

    // ---- in-memory storage ----
    // keyed by ID for fast O(1) lookups instead of looping through arrays
    private array $payments = [];        // payment_id => Payment
    private array $settlements = [];     // batch_id => timestamp

    // ---- state transition rules ----
    // format: 'COMMAND' => ['CURRENT_STATE' => 'NEW_STATE']
    // to add a new command like CHARGEBACK, just add a line here
    private array $transitions = [
        'AUTHORIZE' => [
            'INITIATED' => 'AUTHORIZED',
        ],
        'CAPTURE' => [
            'AUTHORIZED' => 'CAPTURED',
            'PRE_SETTLEMENT_REVIEW' => 'CAPTURED',
        ],
        'VOID' => [
            'INITIATED' => 'VOIDED',
            'AUTHORIZED' => 'VOIDED',
        ],
        'REFUND' => [
            'CAPTURED' => 'REFUNDED',
        ],
        'SETTLE' => [
            'CAPTURED' => 'SETTLED',
            'SETTLED' => 'SETTLED',     // idempotent — settling again is fine
        ],
    ];

    /**
     * Main entry point — decides whether to read from file or stdin.
     */
    public function handle(): int
    {
        $filePath = $this->option('file');

        if ($filePath) {
            return $this->processFile($filePath);
        }

        return $this->processInteractive();
    }

    /**
     * Read commands from a file, one line at a time.
     */
    private function processFile(string $filePath): int
    {
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $lineNum => $line) {
            $result = $this->processLine($line, $lineNum + 1);
            if ($result === null) {
                break;  // EXIT was called
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Read commands from stdin (interactive mode or piped input).
     */
    private function processInteractive(): int
    {
        $this->info('Payment Pipeline - Type commands or EXIT to quit.');
        $this->line('---');

        $lineNum = 0;
        $stdin = fopen('php://stdin', 'r');

        while (($line = fgets($stdin)) !== false) {
            $lineNum++;
            $result = $this->processLine(trim($line), $lineNum);
            if ($result === null) {
                break;  // EXIT was called
            }
        }

        fclose($stdin);
        return Command::SUCCESS;
    }

    /**
     * Process one line of input.
     * Returns null when EXIT is called, otherwise returns the output string.
     * Errors are caught here so one bad line doesn't crash everything.
     */
    private function processLine(string $line, int $lineNum): ?string
    {
        $line = trim($line);

        if ($line === '') {
            return '';
        }

        try {
            // parse the line into command + arguments
            $parsed = $this->parseLine($line);
            $command = $parsed['command'];
            $args = $parsed['args'];

            // handle EXIT separately since it stops the loop
            if ($command === 'EXIT') {
                $this->info('Goodbye!');
                return null;
            }

            // route to the right handler method
            $output = match ($command) {
                'CREATE'     => $this->handleCreate($args),
                'AUTHORIZE'  => $this->handleAuthorize($args),
                'CAPTURE'    => $this->handleCapture($args),
                'VOID'       => $this->handleVoid($args),
                'REFUND'     => $this->handleRefund($args),
                'SETTLE'     => $this->handleSettle($args),
                'SETTLEMENT' => $this->handleSettlement($args),
                'STATUS'     => $this->handleStatus($args),
                'LIST'       => $this->handleList(),
                'AUDIT'      => $this->handleAudit($args),
                default      => "ERROR: Unknown command '{$command}'",
            };

            $this->line($output);
            return $output;

        } catch (\Exception $e) {
            // known errors (bad input, validation failures)
            $msg = "ERROR [line {$lineNum}]: {$e->getMessage()}";
            $this->error($msg);
            return $msg;

        } catch (\Throwable $e) {
            // unexpected errors
            $msg = "ERROR [line {$lineNum}]: An unexpected error occurred. Please check your input.";
            $this->error($msg);
            return $msg;
        }
    }

    // PARSERS

    /**
     * Parse a raw input line into command + args.
     * Handles inline comments (starting with #) and validates argument counts
     */
    private function parseLine(string $line): array
    {
        // split by whitespace into tokens
        $tokens = preg_split('/\s+/', $line);
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));

        if (empty($tokens)) {
            throw new \Exception('Empty command');
        }

        // process tokens and handle inline comments
        $cleaned = [];
        foreach ($tokens as $i => $token) {
            // at position 2+, if token starts with # → it's a comment, stop here
            if ($i >= 2 && str_starts_with($token, '#')) {
                break;
            }

            // at position 2+, if token contains # in the middle (e.g. "abc#comment")
            // keep the part before # and stop
            if ($i >= 2 && str_contains($token, '#')) {
                $before = substr($token, 0, strpos($token, '#'));
                if ($before !== '') {
                    $cleaned[] = $before;
                }
                break;
            }

            // keep the normal token
            $cleaned[] = $token;
        }

        // first token is the command, rest are arguments
        $command = strtoupper($cleaned[0]);
        $args = array_slice($cleaned, 1);

        // check argument count is correct for this command
        $this->validateArgs($command, $args);

        return ['command' => $command, 'args' => $args];
    }

    /**
     * Check that the command exists and has the right number of arguments.
     * [min, max] — min and max number of args allowed.
     */
    private function validateArgs(string $command, array $args): void
    {
        $rules = [
            'CREATE'     => [4, 4],     // payment_id, amount, currency, merchant_id
            'AUTHORIZE'  => [1, 1],     // payment_id
            'CAPTURE'    => [1, 1],     // payment_id
            'VOID'       => [1, 2],     // payment_id, [reason_code]
            'REFUND'     => [1, 2],     // payment_id, [amount]
            'SETTLE'     => [1, 1],     // payment_id
            'SETTLEMENT' => [1, 1],     // batch_id
            'STATUS'     => [1, 1],     // payment_id
            'LIST'       => [0, 0],     // no args
            'AUDIT'      => [1, 1],     // payment_id
            'EXIT'       => [0, 0],     // no args
        ];

        if (!isset($rules[$command])) {
            throw new \Exception("Unknown command: {$command}");
        }

        [$min, $max] = $rules[$command];
        $count = count($args);

        if ($count < $min || $count > $max) {
            throw new \Exception(
                "{$command} expects {$min}" . ($min !== $max ? " to {$max}" : '') . " argument(s), got {$count}"
            );
        }
    }

    // HELPERS

    /**
     * Validate and normalize a money amount string.
     * Returns the normalized amount (e.g. "10.00") or null if invalid.
     * Uses bcmath to avoid floating point precision problems.
     */
    private function validateAmount(string $value): ?string
    {
        // must be digits with optional up to 2 decimal places
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        // must be greater than zero
        if (bccomp($value, '0', 2) <= 0) {
            return null;
        }

        // normalize to exactly 2 decimal places (e.g. "10" becomes "10.00")
        return bcadd($value, '0', 2);
    }

    /**
     * Look up a payment by ID.
     * Returns the Payment object if found, or an error string if not.
     */
    private function findPaymentOrFail(string $paymentId): Payment|string
    {
        if (!isset($this->payments[$paymentId])) {
            return "ERROR: Payment {$paymentId} not found";
        }
        return $this->payments[$paymentId];
    }

    /**
     * Check if a state transition is allowed for this command + current state.
     * Returns null if OK, or an error string if not allowed.
     */
    private function checkTransition(string $command, Payment $payment): ?string
    {
        if (!isset($this->transitions[$command])) {
            return "ERROR: No transitions defined for {$command}";
        }

        if (!isset($this->transitions[$command][$payment->state])) {
            return "ERROR: Cannot {$command} payment {$payment->paymentId} — current state is {$payment->state}";
        }

        return null;    // transition is allowed
    }

    // COMMAND HANDLERS
    
    /**
     * CREATE <payment_id> <amount> <currency> <merchant_id>
     *
     * Creates a new payment in INITIATED state.
     *
     * Idempotency rules:
     * - Same ID + same data → no error, no change (idempotent)
     * - Same ID + different data → mark existing as FAILED, reject new one
     */
    private function handleCreate(array $args): string
    {
        $paymentId = $args[0];
        $currency = strtoupper($args[2]);
        $merchantId = $args[3];

        // validate amount
        $amount = $this->validateAmount($args[1]);
        if ($amount === null) {
            return "ERROR: Invalid amount '{$args[1]}'. Must be a positive number (e.g. 10.00).";
        }

        // validate currency format (3 uppercase letters)
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return "ERROR: Invalid currency '{$currency}'. Must be 3 uppercase letters.";
        }

        // check currency is in the supported list (from config)
        $supported = config('payment.supported_currencies');
        if (!in_array($currency, $supported)) {
            return "ERROR: Currency '{$currency}' not supported. Supported: " . implode(', ', $supported);
        }

        // check if payment already exists
        if (isset($this->payments[$paymentId])) {
            $existing = $this->payments[$paymentId];

            // same data = idempotent, just say OK
            if ($existing->amount === $amount
                && $existing->currency === $currency
                && $existing->merchantId === $merchantId) {
                return "OK: Payment {$paymentId} already exists (idempotent, no changes made)";
            }

            // different data = conflict — mark existing as FAILED and reject
            $existing->changeState('FAILED', 'CREATE_CONFLICT', [
                'reason' => 'Duplicate CREATE with different attributes',
            ]);

            return "ERROR: Payment {$paymentId} already exists with different attributes. Marked as FAILED.";
        }

        // all good — create the payment
        $payment = new Payment($paymentId, $amount, $currency, $merchantId);
        $this->payments[$paymentId] = $payment;

        return "OK: Payment {$paymentId} created [{$amount} {$currency}] merchant={$merchantId}";
    }

    /**
     * AUTHORIZE <payment_id>
     *
     * Moves payment from INITIATED → AUTHORIZED.
     * If the amount is >= the configured review threshold,
     * automatically routes to PRE_SETTLEMENT_REVIEW after authorization.
     */
    private function handleAuthorize(array $args): string
    {
        $paymentId = $args[0];

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        $error = $this->checkTransition('AUTHORIZE', $payment);
        if ($error) return $error;

        // do the transition: INITIATED → AUTHORIZED
        $payment->changeState('AUTHORIZED', 'AUTHORIZE');

        // check if this payment should go through PRE_SETTLEMENT_REVIEW
        // rule: if amount >= configured threshold, route to review
        $threshold = config('payment.review_threshold');
        if ($threshold > 0 && bccomp($payment->amount, (string) $threshold, 2) >= 0) {
            $payment->changeState('PRE_SETTLEMENT_REVIEW', 'ROUTE_TO_REVIEW', [
                'reason' => "Amount {$payment->amount} >= threshold {$threshold}",
            ]);
            return "OK: Payment {$paymentId} authorized → routed to PRE_SETTLEMENT_REVIEW (amount >= {$threshold})";
        }

        return "OK: Payment {$paymentId} authorized";
    }

    /**
     * CAPTURE <payment_id>
     *
     * Moves payment from AUTHORIZED → CAPTURED
     * or from PRE_SETTLEMENT_REVIEW → CAPTURED.
     */
    private function handleCapture(array $args): string
    {
        $paymentId = $args[0];

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        $error = $this->checkTransition('CAPTURE', $payment);
        if ($error) return $error;

        $payment->changeState('CAPTURED', 'CAPTURE');
        return "OK: Payment {$paymentId} captured";
    }

    /**
     * VOID <payment_id> [reason_code]
     *
     * Moves payment from INITIATED → VOIDED or AUTHORIZED → VOIDED.
     * Cannot void after CAPTURED, SETTLED, REFUNDED, VOIDED, or FAILED.
     * Optional reason_code is stored for reporting.
     */
    private function handleVoid(array $args): string
    {
        $paymentId = $args[0];
        $reasonCode = $args[1] ?? null;     // optional

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        $error = $this->checkTransition('VOID', $payment);
        if ($error) return $error;

        // store reason code if provided
        if ($reasonCode) {
            $payment->voidReason = $reasonCode;
        }

        $payment->changeState('VOIDED', 'VOID', ['reason_code' => $reasonCode]);

        $msg = "OK: Payment {$paymentId} voided";
        if ($reasonCode) {
            $msg .= " (reason: {$reasonCode})";
        }
        return $msg;
    }

    /**
     * REFUND <payment_id> [amount]
     *
     * Moves payment from CAPTURED → REFUNDED.
     *
     * If partial refunds are enabled (config) and an amount is given:
     * - Tracks the refunded amount
     * - Stays in CAPTURED until fully refunded
     * - Once remaining = 0, moves to REFUNDED
     *
     * If no amount given or partial refunds disabled:
     * - Does a full refund immediately → REFUNDED
     */
    private function handleRefund(array $args): string
    {
        $paymentId = $args[0];
        $refundAmount = $args[1] ?? null;   // optional

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        $error = $this->checkTransition('REFUND', $payment);
        if ($error) return $error;

        // validate refund amount if one was given
        if ($refundAmount !== null) {
            $validated = $this->validateAmount($refundAmount);
            if ($validated === null) {
                return "ERROR: Invalid refund amount '{$refundAmount}'.";
            }
            $refundAmount = $validated;
        }

        $partialEnabled = config('payment.partial_refunds');

        // --- partial refund path ---
        if ($partialEnabled && $refundAmount !== null) {
            $remaining = $payment->getRemainingAmount();

            // can't refund more than what's left
            if (bccomp($refundAmount, $remaining, 2) > 0) {
                return "ERROR: Refund amount {$refundAmount} exceeds remaining {$remaining} for {$paymentId}";
            }

            // add to the refunded total
            $payment->refundedAmount = bcadd($payment->refundedAmount, $refundAmount, 2);
            $newRemaining = $payment->getRemainingAmount();

            // check if now fully refunded
            if (bccomp($newRemaining, '0.00', 2) <= 0) {
                $payment->changeState('REFUNDED', 'REFUND', [
                    'refund_amount' => $refundAmount,
                    'type' => 'partial_final',
                ]);
                return "OK: Payment {$paymentId} partially refunded {$refundAmount} → now fully refunded";
            }

            // still has balance — stay in CAPTURED, record partial refund in history
            $payment->history[] = [
                'from' => 'CAPTURED',
                'to' => 'CAPTURED',
                'action' => 'PARTIAL_REFUND',
                'refund_amount' => $refundAmount,
                'remaining' => $newRemaining,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            return "OK: Payment {$paymentId} partially refunded {$refundAmount}. Remaining: {$newRemaining} {$payment->currency}";
        }

        // --- full refund path ---
        $refundAmt = $refundAmount ?? $payment->getRemainingAmount();
        $payment->refundedAmount = bcadd($payment->refundedAmount, $refundAmt, 2);
        $payment->changeState('REFUNDED', 'REFUND', ['refund_amount' => $refundAmt]);

        return "OK: Payment {$paymentId} refunded [{$refundAmt} {$payment->currency}]";
    }

    /**
     * SETTLE <payment_id>
     *
     * Per-payment settlement: CAPTURED → SETTLED.
     * Idempotent: settling an already-settled payment just returns OK.
     */
    private function handleSettle(array $args): string
    {
        $paymentId = $args[0];

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        if ($payment->state === 'SETTLED') {
            return "OK: Payment {$paymentId} already settled (no changes)";
        }

        $error = $this->checkTransition('SETTLE', $payment);
        if ($error) return $error;

        $payment->changeState('SETTLED', 'SETTLE');
        return "OK: Payment {$paymentId} settled";
    }

    /**
     * SETTLEMENT <batch_id>
     *
     * Batch/reporting-level operation.
     * Records the batch ID and prints a summary of settled payments.
     *
     * IMPORTANT: This does NOT change any payment's state.
     */
    private function handleSettlement(array $args): string
    {
        $batchId = $args[0];

        // record the batch
        $this->settlements[$batchId] = date('Y-m-d H:i:s');

        // build summary of currently settled payments
        $count = 0;
        $totals = [];

        foreach ($this->payments as $payment) {
            if ($payment->state === 'SETTLED') {
                $count++;
                $curr = $payment->currency;
                if (!isset($totals[$curr])) {
                    $totals[$curr] = '0.00';
                }
                $totals[$curr] = bcadd($totals[$curr], $payment->amount, 2);
            }
        }

        $summary = "OK: Settlement batch '{$batchId}' recorded. {$count} settled payment(s).";
        foreach ($totals as $currency => $total) {
            $summary .= " {$currency}: {$total}";
        }

        return $summary;
    }

    /**
     * STATUS <payment_id>
     *
     * Prints the current state and metadata of a payment.
     * Format: PAYMENT_ID STATE AMOUNT CURRENCY MERCHANT_ID [extras]
     */
    private function handleStatus(array $args): string
    {
        $paymentId = $args[0];

        $payment = $this->findPaymentOrFail($paymentId);
        if (is_string($payment)) return $payment;

        $output = "{$payment->paymentId} {$payment->state} {$payment->amount} {$payment->currency} {$payment->merchantId}";

        // add void reason if payment was voided
        if ($payment->voidReason) {
            $output .= " void_reason={$payment->voidReason}";
        }

        // add refund info if any refunds were made
        if (bccomp($payment->refundedAmount, '0.00', 2) > 0) {
            $output .= " refunded={$payment->refundedAmount} remaining={$payment->getRemainingAmount()}";
        }

        return $output;
    }

    /**
     * LIST
     *
     * Prints a table of all payments and their current states.
     */
    private function handleList(): string
    {
        if (empty($this->payments)) {
            return 'No payments found.';
        }

        $lines = [];

        // header
        $lines[] = str_pad('PAYMENT_ID', 15)
                 . str_pad('STATE', 25)
                 . str_pad('AMOUNT', 12)
                 . str_pad('CURRENCY', 10)
                 . 'MERCHANT';
        $lines[] = str_repeat('-', 70);

        // rows
        foreach ($this->payments as $p) {
            $lines[] = str_pad($p->paymentId, 15)
                     . str_pad($p->state, 25)
                     . str_pad($p->amount, 12)
                     . str_pad($p->currency, 10)
                     . $p->merchantId;
        }

        $lines[] = '--- Total: ' . count($this->payments) . ' payment(s) ---';

        return implode("\n", $lines);
    }

    /**
     * AUDIT <payment_id>
     *
     * Just acknowledges the audit request.
     * Does NOT change any payment state.
     */
    private function handleAudit(array $args): string
    {
        $paymentId = $args[0];
        return "AUDIT RECEIVED for {$paymentId}";
    }
}
