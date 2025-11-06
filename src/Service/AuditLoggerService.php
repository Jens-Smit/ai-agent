<?php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLoggerService
{
    private LoggerInterface $logger;
    private RequestStack $requestStack;

    public function __construct(LoggerInterface $logger, RequestStack $requestStack)
    {
        $this->logger = $logger;
        $this->requestStack = $requestStack;
    }

    /**
     * Loggt ein Security-relevantes Event
     */
    public function logSecurityEvent(string $event, array $context = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $auditData = array_merge($context, [
            'event' => $event,
            'timestamp' => date('c'),
            'ip' => $request?->getClientIp(),
            'userAgent' => $request?->headers->get('User-Agent'),
            'method' => $request?->getMethod(),
            'path' => $request?->getPathInfo(),
        ]);

        $this->logger->warning(json_encode($auditData), ['channel' => 'security']);
    }

    /**
     * Loggt Authentifizierungs-Versuche
     */
    public function logAuthAttempt(string $email, bool $success, string $reason = ''): void
    {
        $this->logSecurityEvent('AUTH_ATTEMPT', [
            'email' => $email,
            'success' => $success,
            'reason' => $reason
        ]);
    }

    /**
     * Loggt unbefugte Zugriffe
     */
    public function logUnauthorizedAccess(string $resource, int $userId): void
    {
        $this->logSecurityEvent('UNAUTHORIZED_ACCESS', [
            'resource' => $resource,
            'userId' => $userId
        ]);
    }

    /**
     * Loggt verdächtige Aktivitäten
     */
    public function logSuspiciousActivity(string $activity, array $details = []): void
    {
        $this->logSecurityEvent('SUSPICIOUS_ACTIVITY', array_merge([
            'activity' => $activity
        ], $details));
    }
}