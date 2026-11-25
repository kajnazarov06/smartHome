<?php

namespace App\Enums;

/** Whether the clock has accepted a user change (an alarm, or a device's settings) as stored in the database. */
enum SyncStatus: string
{
    /** The clock holds exactly this version. */
    case Synced = 'synced';

    /** Saved here, not on the clock yet (clock offline or busy). Pushed when it is reachable. */
    case Pending = 'pending';

    /** The clock refused the change; it keeps its previous version until the user changes it again. */
    case Failed = 'failed';
}
