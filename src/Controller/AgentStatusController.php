<?php 
// src/Controller/AgentStatusController.php (NEW)

namespace App\Controller;

use App\Service\AgentStatusService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/agent', name: 'api_agent_')]
#[OA\Tag(name: 'Agent Status')]
class AgentStatusController extends AbstractController
{
    public function __construct(
        private AgentStatusService $agentStatusService
    ) {}

    #[Route('/status/{sessionId}', name: 'status', methods: ['GET'])]
    #[OA\Get(
        summary: 'Agent Status abrufen',
        description: 'Holt alle Status-Updates für eine Session',
        parameters: [
            new OA\Parameter(
                name: 'sessionId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'since',
                in: 'query',
                required: false,
                description: 'ISO 8601 Timestamp für inkrementelle Updates',
                schema: new OA\Schema(type: 'string', format: 'date-time')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status-Updates',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'sessionId', type: 'string'),
                        new OA\Property(
                            property: 'statuses',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'timestamp', type: 'string'),
                                    new OA\Property(property: 'message', type: 'string')
                                ]
                            )
                        ),
                        new OA\Property(property: 'completed', type: 'boolean'),
                        new OA\Property(property: 'result', type: 'string', nullable: true),
                        new OA\Property(property: 'error', type: 'string', nullable: true)
                    ]
                )
            )
        ]
    )]
    public function getStatus(string $sessionId, Request $request): JsonResponse
    {
        $since = $request->query->get('since');
        
        if ($since) {
            try {
                $sinceDate = new \DateTimeImmutable($since);
                $statuses = $this->agentStatusService->getStatusesSince($sessionId, $sinceDate);
            } catch (\Exception $e) {
                return $this->json([
                    'error' => 'Invalid since parameter'
                ], 400);
            }
        } else {
            $statuses = $this->agentStatusService->getStatuses($sessionId);
        }

        // Parse Status für Completion/Result/Error
        $completed = false;
        $result = null;
        $error = null;

        foreach ($statuses as $status) {
            $message = $status['message'];
            
            if (str_starts_with($message, 'RESULT:')) {
                $completed = true;
                $result = substr($message, 7);
            } elseif (str_starts_with($message, 'ERROR:')) {
                $completed = true;
                $error = substr($message, 6);
            } elseif (str_starts_with($message, 'DEPLOYMENT:')) {
                $completed = true;
                $result = substr($message, 11);
            }
        }

        return $this->json([
            'sessionId' => $sessionId,
            'statuses' => $statuses,
            'completed' => $completed,
            'result' => $result,
            'error' => $error,
            'timestamp' => (new \DateTimeImmutable())->format('c')
        ]);
    }
}