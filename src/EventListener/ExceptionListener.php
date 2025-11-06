<?php
namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;

class ExceptionListener implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private string $appEnv;

    public function __construct(LoggerInterface $logger, string $appEnv)
    {
        $this->logger = $logger;
        $this->appEnv = $appEnv;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    /**
     * Behandelt Exceptions und verursacht keine Information Disclosure
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // ✅ Log Exception (mit Details für interne Verwendung)
        $this->logger->error('Exception occurred', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);

        // ✅ Response: In Prod keine Details, in Dev vollständig
        if ('prod' === $this->appEnv) {
            $response = new JsonResponse([
                'error' => 'Ein Fehler ist aufgetreten',
                'timestamp' => date('c')
            ], 500);
        } else {
            // Development: Details für Debugging
            $response = new JsonResponse([
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'timestamp' => date('c')
            ], 500);
        }

        $event->setResponse($response);
    }
}
