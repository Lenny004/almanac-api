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
     * @param ProblemResponseFactory $problems builds the RFC 7807 responses
     */
    public function __construct(
        private readonly ProblemResponseFactory $problems,
    ) {
    }

    /**
     * Replaces the error response for API requests.
     */
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface) {
            // Known HTTP errors (404 route not found, 405 method not allowed...): keep their status.
            $status = $exception->getStatusCode();
            $response = $this->problems->create(
                $status,
                Response::$statusTexts[$status] ?? 'Error',
                '' !== $exception->getMessage() ? $exception->getMessage() : null,
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

        $event->setResponse($response);
    }
}