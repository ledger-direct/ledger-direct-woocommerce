<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Http;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WP_Error;

/**
 * PSR-18 client over the WordPress HTTP API (`wp_remote_request()`), so the
 * core's oracle and JSON-RPC calls go through whatever transport, proxy and
 * CA bundle the site is configured with - and through the `pre_http_request`
 * filter, which is how tests stub the network.
 *
 * `sslverify` is set explicitly rather than left to the default:
 * INVARIANTS.md makes SSL verification an adapter responsibility, and an
 * explicit true is what makes a later "just disable it to debug" edit show
 * up in review instead of hiding in an omission.
 */
class WpHttpClient implements ClientInterface
{
    private const TIMEOUT_SECONDS = 15;

    private Psr17Factory $factory;

    public function __construct(?Psr17Factory $factory = null)
    {
        $this->factory = $factory ?? new Psr17Factory();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $args = [
            'method' => $request->getMethod(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
            'timeout' => self::TIMEOUT_SECONDS,
            'sslverify' => true,
        ];

        $response = wp_remote_request((string) $request->getUri(), $args);

        if ($response instanceof WP_Error) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception escapes its message itself.
            throw new WpHttpException($request, (string) $response->get_error_message());
        }

        $psrResponse = $this->factory->createResponse((int) wp_remote_retrieve_response_code($response))
            ->withBody($this->factory->createStream((string) wp_remote_retrieve_body($response)));

        $responseHeaders = wp_remote_retrieve_headers($response);
        if (is_object($responseHeaders) && method_exists($responseHeaders, 'getAll')) {
            $responseHeaders = $responseHeaders->getAll();
        }

        foreach ((array) $responseHeaders as $name => $value) {
            $psrResponse = $psrResponse->withHeader((string) $name, $value);
        }

        return $psrResponse;
    }
}
