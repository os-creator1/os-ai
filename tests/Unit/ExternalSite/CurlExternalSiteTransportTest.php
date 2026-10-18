<?php

namespace Tests\Unit\ExternalSite;

use App\Library\ExternalSite\CurlExternalSiteTransport;
use App\Library\ExternalSite\TransportRequest;
use App\Library\ExternalSite\ValidatedTarget;
use Tests\TestCase;

/**
 * The REAL cURL transport against a real local socket (PHP's built-in server).
 * Note the transport itself does no URL policy — that is the UrlGuard's job, and
 * is tested separately — so a loopback server is a legitimate test double here.
 *
 * What it proves: the connection goes to the PINNED address (the hostname in the
 * URL does not resolve anywhere), the Host header is still the original name, a
 * body is cut at the byte limit while streaming, a wrong content type aborts, a
 * slow server times out, a redirect is returned and NOT followed, and no cookie or
 * credential is ever sent.
 */
class CurlExternalSiteTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::$port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
        fclose($probe);

        $router = dirname(__DIR__, 2).'/Support/external_site_router.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir().'/external_site_router.out', 'a'], 2 => ['file', sys_get_temp_dir().'/external_site_router.err', 'a']];
        self::$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, $router], $descriptors, $pipes);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $e1, $e2, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100_000);
        }

        self::fail('The test web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        parent::tearDownAfterClass();
    }

    private function fetchLocal(string $path, int $maxBytes = 1_500_000, int $timeout = 5, array $types = ['text/html']): \App\Library\ExternalSite\TransportResponse
    {
        // The name "pinned-site.example" resolves nowhere: only the pin to 127.0.0.1 can make this connect.
        $target = new ValidatedTarget('http://pinned-site.example:'.self::$port.$path, 'http', 'pinned-site.example', self::$port, '127.0.0.1');

        return (new CurlExternalSiteTransport())->get(new TransportRequest($target, $maxBytes, 2, $timeout, 'MotionGroveSiteAudit/1.0', 'text/html', $types));
    }

    public function test_the_connection_goes_to_the_pinned_address_with_the_original_host_header(): void
    {
        $response = $this->fetchLocal('/ok');

        $this->assertNull($response->error);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('host=pinned-site.example:'.self::$port, $response->body);
        $this->assertSame('127.0.0.1', $response->primaryIp);
    }

    public function test_a_body_is_cut_at_the_byte_limit_while_streaming(): void
    {
        $response = $this->fetchLocal('/big', maxBytes: 250_000);

        $this->assertSame('too_large', $response->error);
        $this->assertSame('', $response->body, 'Nothing beyond the bound is returned.');
    }

    public function test_a_response_of_the_wrong_type_is_aborted(): void
    {
        $this->assertSame('content_type_not_allowed', $this->fetchLocal('/pdf')->error);
    }

    public function test_a_redirect_is_returned_and_never_followed(): void
    {
        $response = $this->fetchLocal('/redirect');

        $this->assertNull($response->error);
        $this->assertSame(302, $response->status);
        $this->assertSame('http://169.254.169.254/latest/meta-data/', $response->header('location'));
    }

    public function test_no_cookie_or_credential_is_ever_sent(): void
    {
        $this->fetchLocal('/cookie-set');
        $echo = $this->fetchLocal('/cookie-echo');

        $this->assertStringContainsString('cookies=[]', $echo->body);
        $this->assertStringContainsString('auth=none', $echo->body);
        $this->assertStringContainsString('ua=MotionGroveSiteAudit/1.0', $echo->body);
    }

    public function test_an_unreachable_pinned_address_is_a_connect_failure_not_a_dns_fallback(): void
    {
        $target = new ValidatedTarget('http://pinned-site.example:1/ok', 'http', 'pinned-site.example', 1, '127.0.0.1');
        $response = (new CurlExternalSiteTransport())->get(new TransportRequest($target, 1000, 1, 2, 'x', 'text/html', ['text/html']));

        $this->assertContains($response->error, ['connect_failed', 'timeout']);
    }

    public function test_a_slow_server_times_out(): void
    {
        $started = microtime(true);
        $response = $this->fetchLocal('/slow', timeout: 1);

        $this->assertSame('timeout', $response->error);
        $this->assertLessThan(3, microtime(true) - $started);
        sleep(3); // let the single-threaded test server finish the abandoned request
    }
}
