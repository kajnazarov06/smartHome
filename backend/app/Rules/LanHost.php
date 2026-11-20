<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A device address the backend may call: a private/link-local/loopback IP or a `.local`
 * mDNS hostname. Keeps the device proxy from being used to reach arbitrary internet hosts.
 */
class LanHost implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail('The :attribute must be a local network IP address or a .local hostname.');
        }
    }

    public static function passes(string $host): bool
    {
        $host = strtolower(trim($host));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Public addresses pass this filter; private, reserved, loopback and link-local ones don't.
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+local$/', $host);
    }
}
