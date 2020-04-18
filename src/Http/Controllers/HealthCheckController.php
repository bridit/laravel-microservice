<?php

declare(strict_types=1);

namespace Bridit\Microservices\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HealthCheckController
{

  /**
   * @param Request $request
   * @return Response
   */
  public function check(Request $request): Response
  {
    $contentType = $request->expectsJson() ? 'application/json' : 'text/html';

    return (new Response(null, 200))->header('Content-Type', $contentType);
  }

}
