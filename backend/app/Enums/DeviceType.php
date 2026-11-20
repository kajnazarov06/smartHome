<?php

namespace App\Enums;

/**
 * Device types this backend can pair and control. The value is what the device reports
 * in `GET /api/info` (`type`) and in its mDNS TXT record.
 *
 * Stored as a plain string column: adding a type is a new case here, with no migration.
 * The UI renders a device from `type` + `capabilities`, so a new case needs no new table or endpoint.
 */
enum DeviceType: string
{
    case Clock = 'clock';

    public function label(): string
    {
        return match ($this) {
            self::Clock => 'Clock station',
        };
    }
}
