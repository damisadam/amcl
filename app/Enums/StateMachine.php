<?php

namespace App\Enums;

use App\Exceptions\InvalidTransition;

/** Explicit state machine: each enum declares its allowed transitions. */
trait StateMachine
{
    /** @return array<string, list<self>> */
    abstract public static function transitions(): array;

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, static::transitions()[$this->value] ?? [], true);
    }

    public function assertCanTransitionTo(self $to, ?string $subject = null): void
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidTransition::of($subject ?? class_basename(static::class), $this, $to);
        }
    }

    public function isTerminal(): bool
    {
        return empty(static::transitions()[$this->value] ?? []);
    }
}
