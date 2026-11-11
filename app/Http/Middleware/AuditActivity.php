<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Library\Audit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditActivity
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('audit_http', true);
        $actor = $request->user();
        $status = 500;
        $response = null;
        $exception = null;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $exception = $response->exception ?? null;

            return $response;
        } catch (\Throwable $error) {
            $exception = $error;
            $status = match (true) {
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                $error instanceof AuthorizationException => $error->hasStatus() ? $error->status() : 403,
                $error instanceof ValidationException => $error->status,
                $error instanceof HttpResponseException => $error->getResponse()->getStatusCode(),
                $error instanceof AuthenticationException => 401,
                $error instanceof ModelNotFoundException => 404,
                default => 500,
            };

            throw $error;
        } finally {
            $this->record($request, $status, $response, $actor ?? $request->user(), $exception);
        }
    }

    public function record(Request $request, int $status, ?Response $response = null, ?User $actor = null, ?\Throwable $exception = null): void
    {
        $request->attributes->set('audit_http', true);
        $request->attributes->set('audit_request_recorded', true);
        $details = [
            'route' => $request->route()?->getName(),
            'route_template' => $request->route()?->uri(),
            'method' => $request->method(),
            'status' => $status,
        ];
        $parameters = [];
        $subject = null;
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if ($value instanceof Model) {
                $parameters[$key] = $value->getKey();
                if (is_int($value->getKey())) {
                    $subject ??= $value;
                }
            } elseif (in_array($key, ['batch', 'board', 'pool', 'assignment', 'user', 'evaluation', 'survey', 'expert', 'question', 'unit', 'designation'], true) &&
                is_scalar($value) && preg_match('/\A[0-9a-f-]{1,36}\z/i', (string) $value)) {
                $parameters[$key] = $value;
            }
        }
        $details['parameters'] = $parameters;
        if ($exception) {
            $details['exception'] = class_basename($exception);
        }

        $uploads = [];
        foreach (Arr::dot($request->allFiles()) as $file) {
            if ($file instanceof UploadedFile) {
                $uploads[] = [
                    'name' => $this->filename($file->getClientOriginalName()),
                    'bytes' => $file->isValid() ? $file->getSize() : null,
                    'upload_error' => $file->getError(),
                ];
            }
        }
        if ($uploads) {
            $details['uploads'] = $uploads;
        }
        $disposition = $response?->headers->get('Content-Disposition');
        if ($disposition && str_starts_with(strtolower($disposition), 'attachment')) {
            $details['download'] = [
                'outcome' => 'prepared',
                'disposition' => mb_substr($disposition, 0, 500),
                'content_type' => $response->headers->get('Content-Type'),
                'bytes' => $response instanceof BinaryFileResponse ? $response->getFile()->getSize() : null,
            ];
        }

        $action = match (true) {
            $status >= 500 => 'request.failed',
            $status >= 400, $exception instanceof ValidationException, $exception instanceof AuthenticationException => 'request.denied',
            $request->isMethod('GET'), $request->isMethod('HEAD') => 'request.view',
            default => 'request.action',
        };
        $audit = app(Audit::class);
        $response?->headers->set('X-Audit-Request-ID', $audit->requestId($request));
        $audit->record($action, $details, $subject, $actor);
    }

    private function filename(string $name): string
    {
        return mb_substr(preg_replace('/[[:cntrl:]]/', '', basename(str_replace('\\', '/', $name))), 0, 255);
    }
}
