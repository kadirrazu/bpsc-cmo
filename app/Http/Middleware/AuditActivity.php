<?php

namespace App\Http\Middleware;

use App\Services\Library\Audit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditActivity
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user();
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (\Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() :
                ($e instanceof AuthorizationException ? 403 :
                ($e instanceof ValidationException ? 422 :
                ($e instanceof HttpResponseException ? $e->getResponse()->getStatusCode() :
                ($e instanceof AuthenticationException ? 401 :
                ($e instanceof ModelNotFoundException ? 404 : 500)))));
            throw $e;
        } finally {
            app(Audit::class)->record($status >= 400 ? 'request.denied' : ($request->isMethod('GET') ? 'request.view' : 'request.action'), [
                'route' => $request->route()?->getName(), 'method' => $request->method(), 'status' => $status,
                'parameters' => array_map(fn ($p) => $p instanceof Model ? $p->getKey() : $p, $request->route()?->parameters() ?? []),
            ], actor: $actor ?? $request->user());
        }
    }
}
