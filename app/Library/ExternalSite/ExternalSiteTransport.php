<?php

namespace App\Library\ExternalSite;

/**
 * The only thing in the module that touches a socket. It performs ONE GET to the
 * pinned address of the request's target and never follows a redirect: the
 * SafeFetcher does that, re-validating every hop. The real implementation is
 * CurlExternalSiteTransport; tests and fake-driver acceptance use a fixture.
 */
interface ExternalSiteTransport
{
    public function get(TransportRequest $request): TransportResponse;
}
