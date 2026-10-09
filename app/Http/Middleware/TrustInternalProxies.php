<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustInternalProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        return config('app.trusted_proxies');
    }
}
