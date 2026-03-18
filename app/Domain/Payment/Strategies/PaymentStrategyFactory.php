<?php

namespace App\Domain\Payment\Strategies;

use App\Enums\PaymentMethod;
use InvalidArgumentException;

/**
 * Payment Strategy Factory
 *
 * FIXED: Uses PaymentMethod enum instead of strings
 *
 * Creates appropriate payment strategy based on payment method
 */
final class PaymentStrategyFactory
{
    private array $strategies = [];

    public function __construct()
    {
        // Register all available payment strategies
        $this->strategies = [
            new CashPaymentStrategy(),
            new EPayPaymentStrategy(),
        ];
    }

    /**
     * Create payment strategy for given payment method
     *
     * @param string|PaymentMethod $paymentMethod
     * @throws InvalidArgumentException if no strategy found
     */
    public function make(string|PaymentMethod $paymentMethod): PaymentStrategy
    {
        // Convert enum to string if needed
        $methodValue = $paymentMethod instanceof PaymentMethod
            ? $paymentMethod->value
            : $paymentMethod;

        foreach ($this->strategies as $strategy) {
            if ($strategy->canProcess($methodValue)) {
                return $strategy;
            }
        }

        throw new InvalidArgumentException(
            "No payment strategy found for payment method: {$methodValue}"
        );
    }

    /**
     * Get all available payment methods
     */
    public function getAvailablePaymentMethods(): array
    {
        return PaymentMethod::available();
    }

    /**
     * Register a new payment strategy
     */
    public function register(PaymentStrategy $strategy): void
    {
        $this->strategies[] = $strategy;
    }
}
