<?php

namespace Gerenuk\Nudge\Http;

use Illuminate\Http\JsonResponse;

/**
 * The one shape of an API error body: `code` and `message`, plus `errors` for validation failures.
 */
class ApiError
{
    /**
     * @param  array<string, string|list<string|null>>  $headers
     * @param  array<string, list<string>>|null  $errors
     */
    public static function response(ApiErrorCode $code, string $message, int $status, array $headers = [], ?array $errors = null): JsonResponse
    {
        $body = ['code' => $code->value, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
