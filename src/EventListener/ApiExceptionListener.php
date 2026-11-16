<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Http\ProblemResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns every exception thrown under /api into an RFC 7807 JSON response.
 *
 * Priority -64 runs after Symfony logs the exception (priority 0)
 * and before its default HTML error page (priority -128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final class ApiExceptionListener
{
    /**
     * Fixed messages for common errors, so internal details (like full URLs) never reach the client.
     */
    private const DETAILS = [
        Response::HTTP_UNAUTHORIZED => 'A valid access token is required.',
        Response::HTTP_FORBIDDEN => 'You are not allowed to access this resource.',
        Response::HTTP_NOT_FOUND => 'The requested resource was not found.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'This HTTP method is not allowed for this resource.',
    ];

    /**
     * @param ProblemResponseFactory $problems builds the RFC 7807 responses
     */
    public function __construct(
        private readonly ProblemResponseFactory $problems,
    ) {
    }

    /**
     * Replaces the error response for API requests.
     *
     * @param ExceptionEvent $event the event holding the request and the thrown exception
     */
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface) {
            // Known HTTP errors (401, 404, 405...): keep their status and headers (e.g. Allow: GET).
            $status = $exception->getStatusCode();
            $response = $this->problems->create(
                $status,
                Response::$statusTexts[$status] ?? 'Error',
                self::DETAILS[$status] ?? ('' !== $exception->getMessage() ? $exception->getMessage() : null),
            );
            $response->headers->add($exception->getHeaders());
        } else {
            // Unexpected errors: never leak internal details to the client.
            $response = $this->problems->create(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Internal Server Error',
                'An unexpected error occurred.',
            );
        }

        // RFC 6750: every 401 tells the client which authentication scheme to use.
        if (Response::HTTP_UNAUTHORIZED === $response->getStatusCode() && !$response->headers->has('WWW-Authenticate')) {
            $response->headers->set('WWW-Authenticate', 'Bearer');
        }

        $event->setResponse($response);
    }
}