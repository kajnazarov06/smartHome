<?php

namespace App\Rules;

use App\Devices\Discovery\SubnetProber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** An IPv4 CIDR the subnet prober accepts (/22 to /32); SubnetProber::hosts() owns the limits. */
class Subnet implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            SubnetProber::hosts((string) $value);
        } catch (InvalidArgumentException $e) {
            $fail("The :attribute is invalid: {$e->getMessage()}");
        }
    }
}
