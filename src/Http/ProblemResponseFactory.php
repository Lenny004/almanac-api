<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds RFC 7807 "problem details" responses, the single error format of the API.
 */
final class ProblemResponseFactory
{
    /**
     * @param int $status HTTP status code
     * @param string $title short, human-readable summary of the problem
     * @param string|null $detail explanation specific to this request
     * @param array<string, mixed> $extra additional members, e.g. validation violations
     */
    public function create(int $status, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status];

        if (null !== $detail) {
            $body['detail'] = $detail;
        }

        return new JsonResponse($body + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }
}
