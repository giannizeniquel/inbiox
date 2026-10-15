<?php

declare(strict_types=1);

namespace App\Compartido\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The single place that turns an unexpected exception on an /api route into the
 * generic 500 envelope `{"error":"Internal server error","reference":"<16 hex>"}`.
 *
 * HTTP exceptions (404, 403, 400...) and security exceptions keep their
 * framework handling. Specific error arms in controllers still run first,
 * because they catch before the exception reaches the kernel.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class InternalServerErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ('/api' !== $path && !str_starts_with($path, '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof HttpExceptionInterface
            || $exception instanceof AuthenticationException
            || $exception instanceof AccessDeniedException
        ) {
            return;
        }

        $reference = bin2hex(random_bytes(8));
        $this->logger->error('Unhandled exception {class}: {message}', [
            'reference' => $reference,
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        $event->setResponse(new JsonResponse(['error' => 'Internal server error', 'reference' => $reference], 500));
    }
}
