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
        ?string $what = null,
        ?string $where = null,

        // Nur die nötigsten aktiven Parameter lassen wir drin
        ?int $page = 1,
        ?int $size = 50,

        // --- Der Rest komplett auskommentiert zum Debuggen ---
        /*
        ?string $jobField = null,
        #[With(minimum: 1, maximum: 100)]
        ?int $publishedSince = null,
        #[With(enum: ['true','false'])]
        ?string $temporaryWork = 'true',
        #[With(enum: ['1','2','4','34'])]
        ?string $offerType = null,
        #[With(pattern: '/^(1|2)(;(1|2))*$/')]
        ?string $fixedTerm = null,
        #[With(pattern: '/^(vz|tz|snw|ho|mj)(;(vz|tz|snw|ho|mj))*$/')]
        ?string $workingHours = null,
        #[With(enum: ['true','false'])]
        ?string $disability = null,
        #[With(enum: ['true','false'])]
        ?string $corona = null,
        #[With(minimum: 1, maximum: 200)]
        ?int $radius = null,
        ?string $employer = null,
        */
    ): array
    {
        $this->logger->info('JobSearchTool execution started', compact(
            'what', 'where', 'page', 'size'
        ));

        try {
            $queryParams = [];

            if ($what !== null) {
                $queryParams['was'] = $what;
            }

            if ($where !== null) {
                $queryParams['wo'] = $where;
            }

            $queryParams['page'] = $page;
            $queryParams['size'] = $size;

            // Zusammenschlüsse der auskommentierten Parameter raus
            /*
            if ($jobField !== null) {
                $queryParams['berufsfeld'] = $jobField;
            }
            if ($employer !== null) {
                $queryParams['arbeitgeber'] = $employer;
            }
            if ($publishedSince !== null) {
                $queryParams['veroeffentlichtseit'] = $publishedSince;
            }
            $queryParams['zeitarbeit'] = $temporaryWork ? 'true' : 'false';
            if ($offerType !== null) {
                $queryParams['angebotsart'] = $offerType;
            }
            if ($fixedTerm !== null) {
                $queryParams['befristung'] = $fixedTerm;
            }
            if ($workingHours !== null) {
                $queryParams['arbeitszeit'] = $workingHours;
            }
            if ($disability !== null) {
                $queryParams['behinderung'] = $disability ? 'true' : 'false';
            }
            if ($corona !== null) {
                $queryParams['corona'] = $corona ? 'true' : 'false';
            }
            if ($radius !== null) {
                $queryParams['umkreis'] = $radius;
            }
            */

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/pc/v4/jobs', [
                'headers' => [
                    'X-API-Key' => self::API_KEY,
                    'Accept' => 'application/json',
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray();

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'status' => 'success',
                    'data' => $content,
                ];
            } else {
                return [
                    'status' => 'api_error',
                    'message' => 'API call returned an error.',
                    'statusCode' => $statusCode,
                    'details' => $content,
                ];
            }
        } catch (TransportExceptionInterface $e) {
            return [
                'status' => 'network_error',
                'message' => 'Failed to connect to the job search API.',
                'error' => $e->getMessage(),
            ];
        } catch (HttpExceptionInterface $e) {
            return [
                'status' => 'http_error',
                'message' => 'An HTTP error occurred during the API call.',
                'statusCode' => $e->getResponse()->getStatusCode(),
                'details' => $e->getResponse()->toArray(false),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }
}
