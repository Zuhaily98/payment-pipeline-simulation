<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new \App\Console\Commands\PaymentPipeline();
    }

    //Helper to call the private parseLine method.
    private function parse(string $line): array
    {
        $method = new \ReflectionMethod($this->command, 'parseLine');
        $method->setAccessible(true);
        return $method->invoke($this->command, $line);
    }

    // PARSER: Inline comments after third token

    /**
     * "CREATE P1001 10.00 MYR M01 # test payment"
     * # is at token index 5, which is >= 2, so it's a comment.
     * Should be stripped — only 4 args remain.
     */
    public function test_comment_after_create_args_is_stripped(): void
    {
        $result = $this->parse('CREATE P1001 10.00 MYR M01 # test payment');

        $this->assertEquals('CREATE', $result['command']);
        $this->assertEquals(['P1001', '10.00', 'MYR', 'M01'], $result['args']);
    }

    /**
     * "AUTHORIZE P1001 # retry"
     * # is at token index 2, which is >= 2, so it's a comment.
     * Result should be just AUTHORIZE P1001.
     */
    public function test_comment_after_authorize_is_stripped(): void
    {
        $result = $this->parse('AUTHORIZE P1001 # retry');

        $this->assertEquals('AUTHORIZE', $result['command']);
        $this->assertEquals(['P1001'], $result['args']);
    }

    /**
     * "SETTLE P1001 # settling again"
     * Same rule — # at index 2 is a comment.
     */
    public function test_comment_after_settle_is_stripped(): void
    {
        $result = $this->parse('SETTLE P1001 # settling again');

        $this->assertEquals('SETTLE', $result['command']);
        $this->assertEquals(['P1001'], $result['args']);
    }

    /**
     * "VOID P1001 DUPLICATE # customer called"
     * # at index 3 — comment. Keep VOID P1001 DUPLICATE.
     */
    public function test_comment_after_void_with_reason_is_stripped(): void
    {
        $result = $this->parse('VOID P1001 DUPLICATE # customer called');

        $this->assertEquals('VOID', $result['command']);
        $this->assertEquals(['P1001', 'DUPLICATE'], $result['args']);
    }

    /**
     * Edge case: # in the middle of a token at position >= 2.
     * "REFUND P1001 5.00#comment" — should keep "5.00", strip "#comment".
     */
    public function test_hash_in_middle_of_token_at_position_2_plus(): void
    {
        $result = $this->parse('REFUND P1001 5.00#comment');

        $this->assertEquals('REFUND', $result['command']);
        $this->assertEquals(['P1001', '5.00'], $result['args']);
    }

    // PARSER: # at beginning of line is NOT a comment

    /**
     * "# CREATE P1002 11.00 MYR M01"
     * # is at token index 0 — NOT a comment.
     * Treated as the command name, which is unknown → error.
     */
    public function test_hash_at_start_of_line_is_not_a_comment(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown command');

        $this->parse('# CREATE P1002 11.00 MYR M01');
    }

    /**
     * "# just a line"
     * Same — # at index 0 is not a comment.
     */
    public function test_hash_only_line_is_malformed(): void
    {
        $this->expectException(\Exception::class);
        $this->parse('# just a line');
    }

    /**
     * "CREATE #P1001 10.00 MYR M01"
     * # is in the second token (index 1), which is < 2.
     * So it's treated as a normal character — #P1001 becomes the payment_id.
     */
    public function test_hash_in_second_token_is_literal(): void
    {
        $result = $this->parse('CREATE #P1001 10.00 MYR M01');

        $this->assertEquals('#P1001', $result['args'][0]);
    }

    // PARSER: General parsing

    public function test_empty_line_throws_error(): void
    {
        $this->expectException(\Exception::class);
        $this->parse('');
    }

    public function test_unknown_command_throws_error(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown command');
        $this->parse('CHARGEBACK P1001');
    }

    public function test_too_few_args_throws_error(): void
    {
        $this->expectException(\Exception::class);
        $this->parse('CREATE P1001 10.00');
    }

    public function test_list_parses_with_no_args(): void
    {
        $result = $this->parse('LIST');

        $this->assertEquals('LIST', $result['command']);
        $this->assertEmpty($result['args']);
    }

    public function test_exit_parses_with_no_args(): void
    {
        $result = $this->parse('EXIT');

        $this->assertEquals('EXIT', $result['command']);
        $this->assertEmpty($result['args']);
    }

    public function test_extra_whitespace_is_handled(): void
    {
        $result = $this->parse('CREATE   P1001   10.00   MYR   M01');

        $this->assertEquals('CREATE', $result['command']);
        $this->assertEquals(['P1001', '10.00', 'MYR', 'M01'], $result['args']);
    }
}
