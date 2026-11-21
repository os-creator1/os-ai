<?php

namespace App\Library\Mobile\Push;

/**
 * The one seam to a push provider. Production binds ExpoPushTransport; every automated test binds a fake, so no
 * real notification can leave the test run.
 */
interface MobilePushTransport
{
    /**
     * @param  list<array<string, mixed>>  $messages  Expo message objects (at most 100)
     * @return list<array{status: string, error?: string}>  one ticket per message, same order. status 'ok' | 'error'
     *         with error being the provider's code (e.g. DeviceNotRegistered). Throws on a transport failure.
     */
    public function send(array $messages): array;
}
