<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public string $paymentId;
    public string $amount;          // stored as string to avoid floating point issues
    public string $currency;
    public string $merchantId;
    public string $state;
    public string $createdAt;

    // optional fields
    public ?string $voidReason = null;
    public string $refundedAmount = '0.00';

    // every state change gets recorded here for auditing
    public array $history = [];

    public function __construct(string $paymentId, string $amount, string $currency, string $merchantId)
    {
        $this->paymentId = $paymentId;
        $this->amount = bcadd($amount, '0', 2);    // normalize to 2 decimal places
        $this->currency = $currency;
        $this->merchantId = $merchantId;
        $this->state = 'INITIATED';
        $this->createdAt = date('Y-m-d H:i:s');

        // record the initial creation in history
        $this->history[] = [
            'from' => null,
            'to' => 'INITIATED',
            'action' => 'CREATE',
            'timestamp' => $this->createdAt,
        ];
    }

    /**
     * Change the payment state and record it in history.
     */
    public function changeState(string $newState, string $action, array $extra = []): void
    {
        $previous = $this->state;
        $this->state = $newState;

        $this->history[] = array_merge([
            'from' => $previous,
            'to' => $newState,
            'action' => $action,
            'timestamp' => date('Y-m-d H:i:s'),
        ], $extra);
    }

    /**
     * Get remaining amount after refunds.
     * Uses bcmath so we don't get floating point weirdness.
     */
    public function getRemainingAmount(): string
    {
        return bcsub($this->amount, $this->refundedAmount, 2);
    }
}
