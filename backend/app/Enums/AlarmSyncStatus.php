<?php

namespace App\Enums;

/** Whether the clock has accepted the alarm as it is stored in the database. */
enum AlarmSyncStatus: string
{
    /** The clock holds exactly this alarm. */
    case Synced = 'synced';

    /** Saved here, not on the clock yet (clock offline or busy). Pushed when it is reachable. */
    case Pending = 'pending';

    /** The clock refused the change; it keeps its previous version until the user edits or deletes the alarm. */
    case Failed = 'failed';
}
