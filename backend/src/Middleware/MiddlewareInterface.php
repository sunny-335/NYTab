<?php
declare(strict_types=1);

namespace Nytab\Middleware;

use Nytab\Core\Request;
use Nytab\Core\Response;

interface MiddlewareInterface
{
    public function handle(Request $req, callable $next): Response;
}
