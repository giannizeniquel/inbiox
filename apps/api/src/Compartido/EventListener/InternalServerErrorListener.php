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
 * El único lugar que convierte una excepción inesperada en una ruta /api en el
 * envoltorio 500 genérico `{"error":"Internal server error","reference":"<16 hex>"}`.
 *
 * Las excepciones HTTP (404, 403, 400...) y las de seguridad conservan su
 * manejo del framework. Los bloques de error específicos de los controladores siguen corriendo primero,
 * porque capturan antes de que la excepción llegue al kernel.
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
