<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant database provisioning
    |--------------------------------------------------------------------------
    |
    | Each tenant gets its own MySQL database AND its own MySQL user whose
    | privileges are scoped to that one database. The app's landlord (root)
    | connection is used only to create/drop those databases and users.
    |
    */

    'db' => [

        // Physical database name = prefix + the tenant slug (hyphens → underscores),
        // e.g. "mysmyle_vision_dental_clinic". Slug is capped so this stays within
        // MySQL's 64-character identifier limit.
        'name_prefix' => 'mysmyle_',

        // Dedicated tenant user name = prefix + tenant id (e.g. "mysmyle_t7").
        // Keep the full name within MySQL's 32-character limit.
        'user_prefix' => 'mysmyle_t',

        // Host part of the tenant MySQL account. '%' works whether the app and
        // database share a host or not; tighten it in production if you can.
        'user_host' => env('TENANT_DB_USER_HOST', '%'),

        // Connection with privileges to CREATE USER / CREATE DATABASE / GRANT.
        'admin_connection' => 'landlord',

        // Privileges granted to a tenant user on its own schema. Migrations need
        // DDL, so this is broad — but only ever on that single database.
        'grant' => 'ALL PRIVILEGES',
    ],

];
