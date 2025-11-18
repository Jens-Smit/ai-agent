<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Psr\Log\LoggerInterface;
use App\Entity\User;
use App\Repository\UserRepository;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use App\Service\GoogleClientService;

#[AsTool(
    name: 'google_calendar_create_event',
    description: 'Creates a new event in the Google Calendar of the currently authenticated user. Requires title, start time, and end time in ISO 8601 format (YYYY-MM-DDTHH:MM:SS). Optional: description, location. IMPORTANT: User must be authenticated with Google OAuth first!'
)]
final class GoogleCalendarCreateEventTool
{
    public function __construct(
        private LoggerInterface $logger,
        private UserRepository $userRepository,
        private GoogleClientService $googleClientService,
    ) {}

    /**
     * @param string $title The title of the calendar event.
     * @param string $startTime The start time in ISO 8601 format (e.g., '2024-12-31T10:00:00').
     * @param string $endTime The end time in ISO 8601 format (e.g., '2024-12-31T11:00:00').
     * @param string $description An optional description for the event (empty string if not provided).
     * @param string $location An optional location for the event (empty string if not provided).
     * @return array Returns an array with status and event details if successful, or an error message.
     */
    public function __invoke(
        string $title,
        string $startTime,
        string $endTime,
        string $description = '',
        string $location = ''
    ): array {
        $this->logger->info('GoogleCalendarCreateEventTool execution started', [
            'title' => $title,
            'startTime' => $startTime,
            'endTime' => $endTime,
        ]);

        try {
            // Hole User aus globalem Kontext (gesetzt vom Handler)
            $userId = $GLOBALS['current_user_id'] ?? null;
            
            if (!$userId) {
                $this->logger->error('No user context available');
                return [
                    'status' => 'error',
                    'message' => 'No user context available. Internal error.',
                ];
            }

            /** @var User|null $user */
            $user = $this->userRepository->find($userId);
            
            if (!$user) {
                $this->logger->error('User not found', ['userId' => $userId]);
                return [
                    'status' => 'error',
                    'message' => 'User not found. Please log in again.',
                    'action_required' => 'login'
                ];
            }

            // ✅ CHECK: Hat der User Google OAuth Tokens?
            if ($user->getGoogleAccessToken() === null || $user->getGoogleRefreshToken() === null) {
                $this->logger->warning('User has no Google OAuth tokens', [
                    'userId' => $user->getId(),
                    'email' => $user->getEmail()
                ]);
                
                return [
                    'status' => 'error',
                    'message' => 'Google Calendar is not connected. Please connect your Google account first by visiting /connect/google',
                    'action_required' => 'google_auth',
                    'auth_url' => '/connect/google'
                ];
            }

            // Format datetime strings to RFC3339 (what Google Calendar expects)
            $formattedStart = $this->formatDateTimeForGoogle($startTime);
            $formattedEnd = $this->formatDateTimeForGoogle($endTime);

            if ($formattedStart === null || $formattedEnd === null) {
                $this->logger->error('Invalid datetime format', [
                    'startTime' => $startTime,
                    'endTime' => $endTime
                ]);
                return [
                    'status' => 'error',
                    'message' => sprintf(
                        'Invalid datetime format. Received startTime: "%s", endTime: "%s". Expected format: YYYY-MM-DDTHH:MM:SS',
                        $startTime,
                        $endTime
                    )
                ];
            }

            $this->logger->info('Formatted datetime for Google Calendar', [
                'formattedStart' => $formattedStart,
                'formattedEnd' => $formattedEnd
            ]);

            $client = $this->googleClientService->getClientForUser($user);
            $service = new Calendar($client);

            // Build event data
            $eventData = [
                'summary' => $title,
                'start' => [
                    'dateTime' => $formattedStart,
                    'timeZone' => 'Europe/Berlin'
                ],
                'end' => [
                    'dateTime' => $formattedEnd,
                    'timeZone' => 'Europe/Berlin'
                ]
            ];

            // Only add optional fields if not empty
            if (!empty($description)) {
                $eventData['description'] = $description;
            }

            if (!empty($location)) {
                $eventData['location'] = $location;
            }

            $this->logger->debug('Creating Google Calendar event', ['eventData' => $eventData]);

            $event = new Event($eventData);
            $createdEvent = $service->events->insert('primary', $event);

            $this->logger->info('Google Calendar event created successfully', [
                'eventId' => $createdEvent->getId()
            ]);

            return [
                'status' => 'success',
                'eventId' => $createdEvent->getId(),
                'htmlLink' => $createdEvent->getHtmlLink(),
                'summary' => $createdEvent->getSummary(),
                'start' => $createdEvent->getStart()->getDateTime(),
                'end' => $createdEvent->getEnd()->getDateTime(),
            ];

        } catch (\Google\Service\Exception $e) {
            $this->logger->error('Google Calendar API error', [
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ]);
            return [
                'status' => 'error',
                'message' => 'Google Calendar API error: ' . $e->getMessage()
            ];
        } catch (\RuntimeException $e) {
            $this->logger->error('Google Client Service error', [
                'message' => $e->getMessage()
            ]);
            
            // Check if it's an auth error
            if (str_contains($e->getMessage(), 'Please re-authenticate')) {
                return [
                    'status' => 'error',
                    'message' => 'Google authentication expired. Please reconnect your Google account at /connect/google',
                    'action_required' => 'google_reauth',
                    'auth_url' => '/connect/google'
                ];
            }
            
            return [
                'status' => 'error',
                'message' => 'Authentication error: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in GoogleCalendarCreateEventTool', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 'error',
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Format datetime string to RFC3339 format required by Google Calendar API
     * 
     * Accepts various input formats and converts to RFC3339 with timezone
     * Examples:
     * - "2025-11-17T12:15:00" -> "2025-11-17T12:15:00+01:00"
     * - "2025-11-17 12:15:00" -> "2025-11-17T12:15:00+01:00"
     * 
     * @param string $datetime
     * @return string|null RFC3339 formatted datetime or null if invalid
     */
    private function formatDateTimeForGoogle(string $datetime): ?string
    {
        try {
            // Create DateTimeImmutable with Europe/Berlin timezone
            $tz = new \DateTimeZone('Europe/Berlin');
            
            // Try to parse the input datetime
            $dt = null;
            
            // Try common formats
            $formats = [
                'Y-m-d\TH:i:s',      // 2025-11-17T12:15:00
                'Y-m-d H:i:s',       // 2025-11-17 12:15:00
                'Y-m-d\TH:i',        // 2025-11-17T12:15
                'Y-m-d H:i',         // 2025-11-17 12:15
            ];

            foreach ($formats as $format) {
                $parsed = \DateTimeImmutable::createFromFormat($format, $datetime, $tz);
                if ($parsed !== false) {
                    $dt = $parsed;
                    break;
                }
            }

            // Fallback: try to create from string directly
            if ($dt === null) {
                $dt = new \DateTimeImmutable($datetime, $tz);
            }

            // Format to RFC3339 (e.g., "2025-11-17T12:15:00+01:00")
            return $dt->format(\DateTime::RFC3339);

        } catch (\Exception $e) {
            $this->logger->error('Failed to format datetime', [
                'datetime' => $datetime,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}