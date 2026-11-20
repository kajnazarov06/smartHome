<?php

return [

    /*
    | Who may create an account: "open" (anyone who can reach the backend),
    | "first_user" (only while no account exists) or "closed".
    */
    'registration' => env('SMARTHOME_REGISTRATION', 'open'),

    'device' => [
        // Major version of the device local API this backend speaks.
        'api_version' => (int) env('DEVICE_API_VERSION', 1),
        'http_timeout' => (float) env('DEVICE_HTTP_TIMEOUT', 3),
        'port' => (int) env('DEVICE_PORT', 80),
        // A device counts as offline when nothing was heard from it for this many seconds.
        // Must stay below 60 s: the spec requires "offline within 60 s of power loss".
        'offline_after' => (int) env('DEVICE_OFFLINE_AFTER', 45),
        'max_alarms' => 10,
    ],

    'discovery' => [
        'mdns_enabled' => (bool) env('DISCOVERY_MDNS', true),
        'service' => '_smartclock._tcp.local',
        'mdns_timeout' => (float) env('DISCOVERY_MDNS_TIMEOUT', 3),
        // Optional CIDR (e.g. 192.168.1.0/24) to probe /api/info across when mDNS finds nothing.
        'probe_subnet' => env('DISCOVERY_PROBE_SUBNET'),
        'probe_timeout' => (float) env('DISCOVERY_PROBE_TIMEOUT', 0.8),
        'probe_concurrency' => (int) env('DISCOVERY_PROBE_CONCURRENCY', 32),
        // How long a discovered (unpaired) device stays listed after it was last seen.
        'ttl' => (int) env('DISCOVERY_TTL', 300),
        // Interval of `devices:discover --watch`.
        'watch_interval' => (int) env('DISCOVERY_WATCH_INTERVAL', 30),
    ],

    'listener' => [
        'poll_interval' => (int) env('LISTENER_POLL_INTERVAL', 5),
        'reconnect_max_backoff' => (int) env('LISTENER_MAX_BACKOFF', 30),
        'reload_interval' => (int) env('LISTENER_RELOAD_INTERVAL', 5),
        // WebSocket ping period; a connection is dropped after 2 unanswered pings.
        'ws_heartbeat' => (int) env('LISTENER_WS_HEARTBEAT', 10),
        // While a WebSocket is open the device is marked as seen this often (devices may push only every 60 s).
        'keepalive_interval' => 5,
        'offline_check_interval' => 5,
    ],

    'history' => [
        'interval' => 60, // seconds between stored readings per device
        'retention_days' => (int) env('HISTORY_RETENTION_DAYS', 30),
    ],
];
