<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ProblemResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Answers an invalid access token with an RFC 7807 problem response.
 */
final class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly ProblemResponseFactory $problemResponseFactory,
    ) {
    }

    /**
     * Builds the 401 response sent when the token is missing a matching user.
     * @param Request $request the request that failed to authenticate
     * @param AuthenticationException $exception why authentication failed
     * @return Response a 401 problem+json response with a WWW-Authenticate header
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $response = $this->problemResponseFactory->create(
            Response::HTTP_UNAUTHORIZED,
            'Unauthorized',
            'The access token is invalid.',
        );
        $response->headers->set('WWW-Authenticate', 'Bearer error="invalid_token"');

        return $response;
    }
}