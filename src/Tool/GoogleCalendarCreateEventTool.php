<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Symfony\Bundle\SecurityBundle\Security;
use App\Service\GoogleClientService; // Import the new service

#[AsTool(
    name: 'google_calendar_create_event',
    description: 'Creates a new event in the Google Calendar of the currently authenticated user. Requires title, start time, and end time. Optional: description, location.'
)]
final class GoogleCalendarCreateEventTool
{
    public function __construct(
        private LoggerInterface $logger,
        private Security $security,
        private GoogleClientService $googleClientService, // Use the new service
    ) {}

    /**
     * @param string $title The title of the calendar event.
     * @param string $startTime The start time of the event in 'YYYY-MM-DDTHH:MM:SS' format (e.g., '2024-12-31T10:00:00').
     * @param string $endTime The end time of the event in 'YYYY-MM-DDTHH:MM:SS' format (e.g., '2024-12-31T11:00:00').
     * @param string|null $description An optional description for the event.
     * @param string|null $location An optional location for the event.
     * @return array Returns an array with status and event details if successful, or an error message.
     */
    public function __invoke(
    
        string $title,
        #[With(pattern: '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}$/')]
        string $startTime,
        #[With(pattern: '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}$/')]
        string $endTime,
        string|null $description = null,
        string|null $location = null
    ): array {
        $this->logger->info('GoogleCalendarCreateEventTool execution started', [
            'title' => $title,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ]);

        try {
            /** @var User|null $user */
            $user = $this->security->getUser();

            if (!$user instanceof User) {
                return ['status' => 'error', 'message' => 'No authenticated user found.'];
            }

            $client = $this->googleClientService->getClientForUser($user);
            $service = new Calendar($client);

            $event = new Event([
                'summary' => $title,
                'description' => $description,
                'location' => $location,
                'start' => ['dateTime' => $startTime, 'timeZone' => 'Europe/Berlin'],
                'end' => ['dateTime' => $endTime, 'timeZone' => 'Europe/Berlin'],
            ]);

            $calendarId = 'primary'; // Use the primary calendar of the user
            $createdEvent = $service->events->insert($calendarId, $event);

            $this->logger->info('Google Calendar event created successfully.', ['eventId' => $createdEvent->getId()]);

            return [
                'status' => 'success',
                'eventId' => $createdEvent->getId(),
                'htmlLink' => $createdEvent->getHtmlLink(),
                'summary' => $createdEvent->getSummary(),
                'start' => $createdEvent->getStart()->getDateTime(),
                'end' => $createdEvent->getEnd()->getDateTime(),
            ];
        } catch (\Google\Service\Exception $e) {
            $this->logger->error('Google Calendar API error: ' . $e->getMessage(), ['error' => $e->getErrors()]);
            return ['status' => 'error', 'message' => 'Google Calendar API error: ' . $e->getMessage()];
        } catch (\RuntimeException $e) { // Catch exceptions from GoogleClientService
            $this->logger->error('Google Client Service error: ' . $e->getMessage(), ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Authentication error: ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('Tool failed with unexpected error', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}