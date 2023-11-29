<?php declare(strict_types=1);
/**
 * XRPL-PHP
 *
 * Copyright (c) Alexander Busse | Hardcastle Technologies
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace XRPL_PHP\Models\Ledger;

use XRPL_PHP\Models\BaseRequest;

/**
 * public API Methods / Ledger Methods
 * https://xrpl.org/ledger_closed.html
 */
class LedgerClosedRequest extends BaseRequest
{
    protected string $command = "ledger_closed";

    public function __construct(
        protected string|int $id
    ) {}
}