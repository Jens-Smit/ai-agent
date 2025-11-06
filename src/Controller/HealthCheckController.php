<?php
namespace App\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class HealthCheckController extends AbstractController
{
    #[Route('/health', name: 'health_check', methods: ['GET'])]
    #[OA\Get(
        path: '/health',
        summary: 'Health Check Endpoint',
        description: 'Prüft die Verfügbarkeit der API und Datenbankverbindung. Wird von Load Balancers und Monitoring-Tools verwendet.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'System ist gesund und funktionsfähig',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['healthy'], example: 'healthy'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2024-01-15T10:30:00+00:00'),
                        new OA\Property(property: 'database', type: 'string', enum: ['connected'], example: 'connected')
                    ]
                )
            ),
            new OA\Response(
                response: 503,
                description: 'Service nicht verfügbar (Datenbankverbindung ausgefallen)',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['unhealthy'], example: 'unhealthy'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2024-01-15T10:30:00+00:00'),
                        new OA\Property(property: 'database', type: 'string', enum: ['disconnected'], example: 'disconnected'),
                        new OA\Property(property: 'error', type: 'string', example: 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')
                    ]
                )
            )
        ]
    )]
    public function health(Connection $connection): JsonResponse
    {
        try {
            // Teste Datenbankverbindung
            $connection->executeQuery('SELECT 1');

            return new JsonResponse([
                'status' => 'healthy',
                'timestamp' => date('c'),
                'database' => 'connected'
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'unhealthy',
                'timestamp' => date('c'),
                'database' => 'disconnected',
                'error' => $e->getMessage()
            ], 503);
        }
    }
}