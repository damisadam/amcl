<?php

namespace App\Exceptions;

class InvalidTransition extends DomainException
{
    public static function of(string $subject, \BackedEnum|string|null $from, \BackedEnum|string $to): self
    {
        $f = $from instanceof \BackedEnum ? $from->value : ($from ?? 'none');
        $t = $to instanceof \BackedEnum ? $to->value : $to;

        return new self("{$subject} cannot move from [{$f}] to [{$t}].");
    }
}
