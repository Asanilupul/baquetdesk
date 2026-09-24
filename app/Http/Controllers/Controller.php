<?php

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

abstract class Controller
{
    /**
     * Log an unexpected failure and return a message that is safe to show in the browser.
     * Application validation / business-rule messages pass through; database and framework
     * internals (SQL, file paths, stack details) are replaced by a reference code.
     */
    protected function errorResponse(Throwable $e, string $context, int $status = 500): JsonResponse
    {
        if ($this->isSafeMessage($e)) {
            return response()->json([
                'data' => null,
                'error' => ['message' => $e->getMessage()],
            ], $e instanceof InvalidArgumentException ? 422 : $status);
        }

        if ($e instanceof UniqueConstraintViolationException) {
            return response()->json([
                'data' => null,
                'error' => ['message' => 'A record with the same unique value (such as username or code) already exists.'],
            ], 409);
        }

        return response()->json([
            'data' => null,
            'error' => ['message' => 'Something went wrong. Please try again or contact support (ref '.$this->logFailure($e, $context).').'],
        ], $status);
    }

    protected function logFailure(Throwable $e, string $context): string
    {
        $reference = strtoupper(Str::random(8));
        Log::error($context.' failed', [
            'reference' => $reference,
            'exception' => $e,
        ]);

        return $reference;
    }

    private function isSafeMessage(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            return false;
        }

        return $e::class === InvalidArgumentException::class
            || $e::class === RuntimeException::class;
    }
}
