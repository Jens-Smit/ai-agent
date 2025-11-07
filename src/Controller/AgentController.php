<?php
namespace App\Controller;

use App\DTO\AgentPromptRequest;
use Symfony\AI\Agent\Agent; 
use Symfony\AI\Platform\Message\Content\TextContent;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use OpenApi\Attributes as OA;
use Nelmio\ApiDocBundle\Annotation\Model;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire; 

class AgentController extends AbstractController
{
    #[Route('/api/agent/prompt', name: 'api_agent_prompt', methods: ['POST'])]
 public function runAgent(
    #[Autowire(service: 'ai.agent.default')] Agent $codingAgent,  
    #[MapRequestPayload] AgentPromptRequest $request
): JsonResponse {
    $task = $request->prompt;

    try {
        // Direkt den Prompt als UserMessage übergeben
        $messages = [new UserMessage($task)];
        $response = $codingAgent->call($messages);

        return new JsonResponse([
            'task_received' => $task,
            'agent_response' => $response->getContent(),
            'communication_log' => $response->getContent(true),
            'status' => 'success',
        ]);

    } catch (\Exception $e) {
        return new JsonResponse([
            'error' => 'Ein interner Fehler ist bei der Agenten-Ausführung aufgetreten.',
            'details' => $e->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
}