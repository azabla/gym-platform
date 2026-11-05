<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies whose X-Forwarded-* headers are believed, so the app sees the
    | https and host the visitor used. "*" trusts whichever proxy calls the
    | app, for hosts like Render where only their proxy can reach it. On a
    | server reachable directly, list the proxy's addresses, comma separated.
    | Left empty, forwarded headers are ignored.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
