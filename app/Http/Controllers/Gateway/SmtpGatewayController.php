<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Library\BusinessEmail\Imap\ImapHostGuard;
use App\Library\BusinessEmail\Imap\SmtpGatewayHandler;
use App\Library\BusinessEmail\Imap\SmtpMailer;
use App\Library\BusinessEmail\Imap\SymfonySmtpMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin HTTP shell around {@see SmtpGatewayHandler}; see its class comment for the security model. */
final class SmtpGatewayController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        return $this->respond('send', $request);
    }

    public function test(Request $request): JsonResponse
    {
        return $this->respond('test', $request);
    }

    private function respond(string $action, Request $request): JsonResponse
    {
        // Always the direct mailer: the gateway must never forward to another gateway.
        $mailer = app()->bound(SmtpMailer::class) ? app(SmtpMailer::class) : new SymfonySmtpMailer();
        $handler = new SmtpGatewayHandler(app()->bound(ImapHostGuard::class) ? app(ImapHostGuard::class) : new ImapHostGuard(), $mailer);

        $headers = [];
        foreach (['x-gateway-timestamp', 'x-gateway-nonce', 'x-gateway-signature'] as $name) {
            $headers[$name] = $request->header($name);
        }

        [$status, $body] = $handler->handle($action, $request->getContent(), $headers);

        return response()->json($body, $status);
    }
}
