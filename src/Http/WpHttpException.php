<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Http;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A request that never produced an HTTP response (DNS, TLS, timeout, ...) -
 * what the WordPress HTTP API reports as a WP_Error. The core catches these
 * per oracle and falls back to the remaining ones.
 */
class WpHttpException extends RuntimeException implements NetworkExceptionInterface
{
    private RequestInterface $request;

    public function __construct(RequestInterface $request, string $message)
    {
        parent::__construct(esc_html($message));

        $this->request = $request;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
