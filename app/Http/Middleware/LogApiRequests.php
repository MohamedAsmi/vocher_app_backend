<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogApiRequests
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'token', 'access_token', 'refresh_token',
        'authorization', 'cookie', 'set-cookie',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $exception = null;
        $response = null;

        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $exception = $error;
            throw $error;
        } finally {
            $this->store($request, $response, $exception, $startedAt);
        }

        return $response;
    }

    private function store(Request $request, ?Response $response, ?Throwable $exception, float $startedAt): void
    {
        try {
            $status = $response?->getStatusCode() ?? $this->exceptionStatus($exception);
            $responseBody = $response ? $this->jsonBody($response->getContent()) : null;
            $errorMessage = $this->exceptionMessage($exception);
            if (! $errorMessage && $status >= 400) {
                $errorMessage = is_array($responseBody) ? ($responseBody['message'] ?? $responseBody['error'] ?? null) : null;
            }

            ApiRequestLog::create([
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status_code' => $status,
                'success' => $status !== null && $status < 400,
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_headers' => $this->redact($request->headers->all()),
                'request_body' => $this->redact($request->all()),
                'response_body' => $responseBody === null ? null : $this->redact($responseBody),
                'error_message' => $errorMessage,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable) {
            // Logging must never change the API response or hide the original exception.
        }
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = in_array(strtolower((string) $key), self::REDACTED_KEYS, true)
                ? '[REDACTED]'
                : $this->redact($item);
        }

        return $redacted;
    }

    private function jsonBody(string $content): mixed
    {
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => mb_substr($content, 0, 10000)];
    }

    private function exceptionStatus(?Throwable $exception): ?int
    {
        if (! $exception) {
            return null;
        }

        return method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
    }

    private function exceptionMessage(?Throwable $exception): ?string
    {
        if (! $exception) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return json_encode($exception->errors(), JSON_UNESCAPED_SLASHES);
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getMessage() !== '') {
            return $exception->getMessage();
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
    }
}
