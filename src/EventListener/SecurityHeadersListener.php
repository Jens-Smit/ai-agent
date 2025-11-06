<?php
namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersListener implements EventSubscriberInterface
{
    private string $appEnv;

    public function __construct(string $appEnv)
    {
        $this->appEnv = $appEnv;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // 1) HTTPS-Redirect nur in prod und nur wenn nicht secure
        if ('prod' === $this->appEnv && !$request->isSecure()) {
            $url = 'https://' . $request->getHost() . $request->getRequestUri();
            $event->setResponse(new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY));
            return;
        }

        // 2) Header nur auf der eigentlichen Response setzen
        $response = $event->getResponse();

        // HSTS nur setzen, wenn die aktuelle Verbindung HTTPS ist
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // CSP
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; " .
            "script-src 'self'; " .
            "style-src 'self'; " .
            "img-src 'self' data: https:; " .
            "font-src 'self'; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'"
        );

        // Weitere Sicherheitsheader
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()'
        );

        // Remove server-identifying headers
        $response->headers->remove('Server');
        $response->headers->remove('X-Powered-By');

        $event->setResponse($response);
    }
}