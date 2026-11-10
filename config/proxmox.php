<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | Name of the cluster used by the Proxmox facade when no connection is
    | selected explicitly with Proxmox::connection('name').
    |
    */

    'default' => env('PROXMOX_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Clusters
    |--------------------------------------------------------------------------
    |
    | "url" is the address of any cluster node, e.g. https://pve1.example.com:8006
    | (port 8006 is added when missing).
    |
    | Authenticate with an API token (recommended): "token_id" in the form
    | user@realm!tokenid and its "token_secret" (Datacenter > Permissions >
    | API Tokens). Alternatively "username" (user@realm, e.g. root@pam) and
    | "password" obtain a ticket, which is cached and renewed automatically.
    |
    | "node" is the default node for calls that need one, e.g. creating a VM.
    | Calls addressing an existing guest look up its node in the cluster
    | resources when no node is given.
    |
    | "verify_ssl" may be true, false or a path to a CA bundle (the cluster CA
    | is /etc/pve/pve-root-ca.pem on every node). Disable verification only
    | on trusted networks.
    |
    */

    'connections' => [

        'default' => [
            'url' => env('PROXMOX_URL'),
            'token_id' => env('PROXMOX_TOKEN_ID'),
            'token_secret' => env('PROXMOX_TOKEN_SECRET'),
            'username' => env('PROXMOX_USERNAME'),
            'password' => env('PROXMOX_PASSWORD'),
            'node' => env('PROXMOX_NODE'),
            'verify_ssl' => env('PROXMOX_VERIFY_SSL', true),
            'timeout' => (int) env('PROXMOX_TIMEOUT', 30),
            'connect_timeout' => (int) env('PROXMOX_CONNECT_TIMEOUT', 10),
            'retries' => (int) env('PROXMOX_RETRIES', 2),
            'retry_delay' => (int) env('PROXMOX_RETRY_DELAY', 300),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    |
    | Tickets obtained with a username and password are valid for two hours
    | and are kept in the cache slightly shorter than that.
    |
    */

    'ticket' => [
        'store' => env('PROXMOX_TICKET_STORE'),
        'prefix' => 'proxmox:ticket:',
        'ttl' => (int) env('PROXMOX_TICKET_TTL', 6600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task waiter
    |--------------------------------------------------------------------------
    |
    | Defaults of Task::wait(): seconds between status polls and the maximum
    | number of seconds to wait for an asynchronous task (UPID).
    |
    */

    'tasks' => [
        'poll_interval' => (int) env('PROXMOX_TASK_POLL_INTERVAL', 2),
        'timeout' => (int) env('PROXMOX_TASK_TIMEOUT', 900),
    ],

];
