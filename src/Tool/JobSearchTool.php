<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsTool(
    name: 'job_search',
    description: 'Searches for job openings using the Jobsuche API of the Bundesagentur für Arbeit.'
)]
final class JobSearchTool
{
    private const API_BASE_URL = 'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service';
    private const API_KEY = 'jobboerse-jobsuche';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(
        string $what = '',
        string $where = '',

        // Nur die nötigsten aktiven Parameter lassen wir drin
        int $page = 1,
        int $size = 5,

        // --- Der Rest komplett auskommentiert zum Debuggen ---
        /*
        string $jobField = '',
        #[With(minimum: 1, maximum: 100)]
        int $publishedSince = 99,
        #[With(enum: ['true','false'])]
        string $temporaryWork = 'true',
        #[With(enum: ['1','2','4','34'])]
        string $offerType = '',
        #[With(pattern: '/^(1|2)(;(1|2))*$/')]
        string $fixedTerm = '',
        #[With(pattern: '/^(vz|tz|snw|ho|mj)(;(vz|tz|snw|ho|mj))*$/')]
        string $workingHours = '',
        #[With(enum: ['true','false'])]
        string $disability = '',
        #[With(enum: ['true','false'])]
        string $corona = '',
        #[With(minimum: 1, maximum: 200)]
        
        string $employer = '',
        */
        int $radius = 25,
    ): array
    {
        $this->logger->info('JobSearchTool execution started', compact(
        'what', 'where', 'page', 'size', 'radius'
        ));

        try {
            $queryParams = [];

            if ($what !== null) {
                $queryParams['was'] = $what;
                $this->logger->debug('Added parameter "was"', ['was' => $what]);
            }

            if ($where !== null) {
                $queryParams['wo'] = $where;
                $this->logger->debug('Added parameter "wo"', ['wo' => $where]);
            }

            $queryParams['page'] = $page;
            $queryParams['size'] = $size;

            if ($radius !== null) {
                $queryParams['umkreis'] = $radius;
                $this->logger->debug('Added parameter "umkreis"', ['umkreis' => $radius]);
            }

            $this->logger->info('Final query parameters built', $queryParams);

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/pc/v4/jobs', [
                'headers' => [
                    'X-API-Key' => self::API_KEY,
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info('API call executed', ['statusCode' => $statusCode]);

            $content = $response->toArray();
            $this->logger->debug('API response content', ['content' => $content]);

            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('JobSearchTool execution succeeded');
                return [
                    'status' => 'success',
                    'data' => $content,
                ];
            } else {
                $this->logger->warning('JobSearchTool API error', [
                    'statusCode' => $statusCode,
                    'details' => $content,
                ]);
                return [
                    'status' => 'api_error',
                    'message' => 'API call returned an error.',
                    'statusCode' => $statusCode,
                    'details' => $content,
                ];
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Network error during JobSearchTool execution', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            return [
                'status' => 'network_error',
                'message' => 'Failed to connect to the job search API.',
                'error' => $e->getMessage(),
            ];
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error during JobSearchTool execution', [
                'statusCode' => $e->getResponse()->getStatusCode(),
                'details' => $e->getResponse()->toArray(false),
                'exception' => $e,
            ]);
            return [
                'status' => 'http_error',
                'message' => 'An HTTP error occurred during the API call.',
                'statusCode' => $e->getResponse()->getStatusCode(),
                'details' => $e->getResponse()->toArray(false),
            ];
        } catch (\Exception $e) {
            $this->logger->critical('Unexpected error during JobSearchTool execution', [
                'exception' => $e,
                'message' => $e->getMessage(),
            ]);
            return [
                'status' => 'error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }

}
