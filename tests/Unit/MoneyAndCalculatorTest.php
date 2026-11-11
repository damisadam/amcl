<?php

namespace Tests\Unit;

use App\Enums\ApplicationStatus;
use App\Exceptions\InvalidTransition;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyAndCalculatorTest extends TestCase
{
    public function test_money_rounding_and_split_are_exact(): void
    {
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('33.33', Money::div('100', 3));
        $this->assertSame('2.50', Money::pct('100', '2.5'));
        $this->assertSame('-1.01', Money::of('-1.005'));
        $parts = Money::split('100.00', 3);
        $this->assertSame(['33.33', '33.33', '33.34'], $parts);
        $this->assertSame('100.00', Money::add(...$parts));
        $this->assertSame('PKR 1,234,567.89', Money::format('1234567.89'));
    }

    public function test_application_state_machine_rejects_invalid_transitions(): void
    {
        $this->assertTrue(ApplicationStatus::Draft->canTransitionTo(ApplicationStatus::Submitted));
        $this->assertFalse(ApplicationStatus::Draft->canTransitionTo(ApplicationStatus::Disbursed));
        $this->assertTrue(ApplicationStatus::Rejected->isTerminal());
        $this->expectException(InvalidTransition::class);
        ApplicationStatus::Submitted->assertCanTransitionTo(ApplicationStatus::Disbursed);
    }
}
