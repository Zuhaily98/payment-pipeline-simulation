<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PaymentPipelineTest extends TestCase
{
    /**
     * Helper: write commands to a temp file.
     * Always adds EXIT at the end so the pipeline stops cleanly.
     */
    private function makeFile(array $commands): string
    {
        $commands[] = 'EXIT';
        $path = tempnam(sys_get_temp_dir(), 'pay_test_');
        file_put_contents($path, implode("\n", $commands));
        return $path;
    }

    // 1. HAPPY-PATH FLOWS

    /**
     * Happy path: CREATE → AUTHORIZE → CAPTURE → SETTLE → STATUS
     *
     * The normal full lifecycle. After settling, STATUS should show SETTLED.
     */
    public function test_happy_path_create_authorize_capture_settle_status(): void
    {
        $file = $this->makeFile([
            'CREATE P1001 10.00 MYR M01',
            'AUTHORIZE P1001',
            'CAPTURE P1001',
            'SETTLE P1001',
            'STATUS P1001',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P1001 created')
            ->expectsOutputToContain('Payment P1001 authorized')
            ->expectsOutputToContain('Payment P1001 captured')
            ->expectsOutputToContain('Payment P1001 settled')
            ->expectsOutputToContain('P1001 SETTLED 10.00 MYR M01');
    }

    /**
     * Happy path: CREATE → AUTHORIZE → VOID
     *
     * Merchant cancels the payment after authorization.
     * STATUS should show VOIDED with the reason code.
     */
    public function test_happy_path_create_authorize_void(): void
    {
        $file = $this->makeFile([
            'CREATE P1002 25.00 MYR M01',
            'AUTHORIZE P1002',
            'VOID P1002 CUSTOMER_REQUEST',
            'STATUS P1002',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P1002 created')
            ->expectsOutputToContain('Payment P1002 authorized')
            ->expectsOutputToContain('Payment P1002 voided')
            ->expectsOutputToContain('P1002 VOIDED 25.00 MYR M01')
            ->expectsOutputToContain('void_reason=CUSTOMER_REQUEST');
    }

    /**
     * Happy path: CREATE → AUTHORIZE → CAPTURE → REFUND
     *
     * Customer asks for a refund after payment was captured.
     * STATUS should show REFUNDED.
     */
    public function test_happy_path_create_authorize_capture_refund(): void
    {
        $file = $this->makeFile([
            'CREATE P1003 50.00 MYR M01',
            'AUTHORIZE P1003',
            'CAPTURE P1003',
            'REFUND P1003',
            'STATUS P1003',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P1003 created')
            ->expectsOutputToContain('Payment P1003 authorized')
            ->expectsOutputToContain('Payment P1003 captured')
            ->expectsOutputToContain('Payment P1003 refunded')
            ->expectsOutputToContain('P1003 REFUNDED 50.00 MYR M01');
    }

    // 2. INVALID TRANSITIONS

    /**
     * Invalid: REFUND before CAPTURE
     *
     * Payment is AUTHORIZED. You can't refund something that hasn't been captured.
     * Should show an error and state should stay AUTHORIZED.
     */
    public function test_invalid_refund_before_capture(): void
    {
        $file = $this->makeFile([
            'CREATE P2001 10.00 MYR M01',
            'AUTHORIZE P2001',
            'REFUND P2001',
            'STATUS P2001',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Cannot REFUND')
            ->expectsOutputToContain('P2001 AUTHORIZED');       // state didn't change
    }

    /**
     * Invalid: CAPTURE before AUTHORIZE
     *
     * Payment is INITIATED. You can't capture without authorizing first.
     * Should show an error and state should stay INITIATED.
     */
    public function test_invalid_capture_before_authorize(): void
    {
        $file = $this->makeFile([
            'CREATE P2002 10.00 MYR M01',
            'CAPTURE P2002',
            'STATUS P2002',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Cannot CAPTURE')
            ->expectsOutputToContain('P2002 INITIATED');        // state didn't change
    }

    /**
     * Invalid: VOID after CAPTURE
     *
     * Payment is CAPTURED. You can't void a captured payment — you'd need to refund.
     * Should show an error and state should stay CAPTURED.
     */
    public function test_invalid_void_after_capture(): void
    {
        $file = $this->makeFile([
            'CREATE P2003 10.00 MYR M01',
            'AUTHORIZE P2003',
            'CAPTURE P2003',
            'VOID P2003',
            'STATUS P2003',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Cannot VOID')
            ->expectsOutputToContain('P2003 CAPTURED');         // state didn't change
    }

    // 3. IDEMPOTENCY

    /**
     * Repeated CREATE with identical attributes
     *
     * Second CREATE has same payment_id, amount, currency, merchant_id.
     * Should be accepted silently — no error, state unchanged.
     */
    public function test_idempotent_create_same_attributes(): void
    {
        $file = $this->makeFile([
            'CREATE P3001 10.00 MYR M01',
            'CREATE P3001 10.00 MYR M01',       // exact same data
            'STATUS P3001',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('idempotent')
            ->expectsOutputToContain('P3001 INITIATED');        // state still INITIATED
    }

    /**
     * Repeated SETTLE on an already-settled payment
     *
     * First SETTLE: CAPTURED → SETTLED.
     * Second SETTLE: already SETTLED — should just say OK, no error.
     */
    public function test_idempotent_settle_already_settled(): void
    {
        $file = $this->makeFile([
            'CREATE P3003 10.00 MYR M01',
            'AUTHORIZE P3003',
            'CAPTURE P3003',
            'SETTLE P3003',
            'SETTLE P3003',                      // second settle
            'STATUS P3003',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('already settled')
            ->expectsOutputToContain('P3003 SETTLED');
    }

    /**
     * CREATE conflict: same payment_id but different attributes.
     *
     * First CREATE succeeds. Second CREATE has different amount.
     * Existing payment should be marked FAILED, second CREATE rejected.
     */
    public function test_create_conflict_marks_existing_as_failed(): void
    {
        $file = $this->makeFile([
            'CREATE P3004 10.00 MYR M01',
            'CREATE P3004 99.00 MYR M01',       // different amount
            'STATUS P3004',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Marked as FAILED')
            ->expectsOutputToContain('P3004 FAILED 10.00 MYR M01');
    }

    /**
     * REFUND on a REFUNDED payment must be rejected.
     */
    public function test_refund_after_refund_rejected(): void
    {
        $file = $this->makeFile([
            'CREATE P3005 50.00 MYR M01',
            'AUTHORIZE P3005',
            'CAPTURE P3005',
            'REFUND P3005',
            'REFUND P3005',                      // already REFUNDED
            'STATUS P3005',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Cannot REFUND')
            ->expectsOutputToContain('P3005 REFUNDED');
    }

    // 4. PARSER BEHAVIOR (integration level)

    /**
     * Parser: Inline comment after args is stripped correctly.
     *
     * "CREATE P4001 10.00 MYR M01 # this is a comment" should work.
     * The payment should be created normally.
     */
    public function test_parser_inline_comment_stripped(): void
    {
        $file = $this->makeFile([
            'CREATE P4001 10.00 MYR M01 # this is a test comment',
            'STATUS P4001',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P4001 created')
            ->expectsOutputToContain('P4001 INITIATED 10.00 MYR M01');
    }

    /**
     * Parser: # at beginning of line is NOT a comment — it's malformed.
     *
     * "# CREATE P4002 11.00 MYR M01" should produce an error.
     */
    public function test_parser_hash_at_start_is_malformed(): void
    {
        $file = $this->makeFile([
            '# CREATE P4002 11.00 MYR M01',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('ERROR');
    }

    /**
     * Parser: AUTHORIZE with inline comment works.
     *
     * "AUTHORIZE P4003 # retry" — # at index 2 is a comment.
     */
    public function test_parser_authorize_with_comment(): void
    {
        $file = $this->makeFile([
            'CREATE P4003 10.00 MYR M01',
            'AUTHORIZE P4003 # retry attempt',
            'STATUS P4003',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P4003 authorized')
            ->expectsOutputToContain('P4003 AUTHORIZED');
    }

    /**
     * Parser: Bad input doesn't crash the pipeline.
     *
     * After a bad line, the next valid commands should still work.
     */
    public function test_parser_bad_input_continues_processing(): void
    {
        $file = $this->makeFile([
            'NONSENSE',
            'CREATE',
            'CREATE P4004 10.00 MYR M01',
            'STATUS P4004',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('P4004 INITIATED 10.00 MYR M01');
    }

    // AUDIT must have no side effects on payment state.
    public function test_audit_no_side_effects(): void
    {
        $file = $this->makeFile([
            'CREATE P5001 10.00 MYR M01',
            'AUDIT P5001',
            'STATUS P5001',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('AUDIT RECEIVED')
            ->expectsOutputToContain('P5001 INITIATED');    // still INITIATED
    }

    /**
     * SETTLE (per-payment) vs SETTLEMENT (batch reporting).
     * SETTLEMENT should NOT change any payment state.
     */
    public function test_settle_vs_settlement(): void
    {
        $file = $this->makeFile([
            'CREATE P5002 10.00 MYR M01',
            'AUTHORIZE P5002',
            'CAPTURE P5002',
            'SETTLE P5002',
            'SETTLEMENT BATCH001',
            'STATUS P5002',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P5002 settled')
            ->expectsOutputToContain("batch 'BATCH001' recorded")
            ->expectsOutputToContain('P5002 SETTLED');
    }

    /**
     * PRE_SETTLEMENT_REVIEW triggered for high-value payments.
     * Default threshold is 1000.00.
     */
    public function test_pre_settlement_review_triggered(): void
    {
        $file = $this->makeFile([
            'CREATE P5003 1500.00 MYR M01',
            'AUTHORIZE P5003',
            'STATUS P5003',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('PRE_SETTLEMENT_REVIEW');
    }

    /**
     * Payment in PRE_SETTLEMENT_REVIEW can still be captured.
     */
    public function test_capture_from_pre_settlement_review(): void
    {
        $file = $this->makeFile([
            'CREATE P5004 2000.00 MYR M01',
            'AUTHORIZE P5004',
            'CAPTURE P5004',
            'STATUS P5004',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P5004 captured')
            ->expectsOutputToContain('P5004 CAPTURED');
    }

    /**
     * LIST command shows all payments.
     */
    public function test_list_shows_all_payments(): void
    {
        $file = $this->makeFile([
            'CREATE P5005 10.00 MYR M01',
            'CREATE P5006 20.00 USD M02',
            'AUTHORIZE P5005',
            'LIST',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('P5005')
            ->expectsOutputToContain('P5006')
            ->expectsOutputToContain('AUTHORIZED')
            ->expectsOutputToContain('INITIATED')
            ->expectsOutputToContain('Total: 2');
    }

    /**
     * STATUS of unknown payment gives a clear error.
     */
    public function test_status_unknown_payment(): void
    {
        $file = $this->makeFile([
            'STATUS UNKNOWN123',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('not found');
    }

    /**
     * Partial refund: stays in CAPTURED until fully refunded.
     */
    public function test_partial_refund_flow(): void
    {
        $file = $this->makeFile([
            'CREATE P5007 100.00 MYR M01',
            'AUTHORIZE P5007',
            'CAPTURE P5007',
            'REFUND P5007 30.00',
            'STATUS P5007',
            'REFUND P5007 70.00',
            'STATUS P5007',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('partially refunded 30.00')
            ->expectsOutputToContain('Remaining: 70.00')
            ->expectsOutputToContain('P5007 CAPTURED')          // still CAPTURED after partial
            ->expectsOutputToContain('fully refunded')
            ->expectsOutputToContain('P5007 REFUNDED');         // now REFUNDED
    }

    /**
     * Void without reason code still works.
     */
    public function test_void_without_reason(): void
    {
        $file = $this->makeFile([
            'CREATE P5008 10.00 MYR M01',
            'VOID P5008',
            'STATUS P5008',
        ]);

        $this->artisan('payment:pipeline', ['--file' => $file])
            ->assertExitCode(0)
            ->expectsOutputToContain('Payment P5008 voided')
            ->expectsOutputToContain('P5008 VOIDED');
    }
}
