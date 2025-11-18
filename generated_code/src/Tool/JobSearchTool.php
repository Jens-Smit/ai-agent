<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\With;
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

    /**
     * Searches for job openings based on various criteria.
     *
     * @param string|null $what Free text search for job title.
     * @param string|null $where Free text search for employment location.
     * @param string|null $jobField Free text search for job field.
     * @param int $page Result page number (default 1).
     * @param int $size Number of results per page (default 50, max 100).
     * @param string|null $employer Employer name.
     * @param int|null $publishedSince Number of days since the job was published (0-100).
     * @param bool $temporaryWork Include temporary work jobs (default true).
     * @param int|null $offerType Type of job offer (1=ARBEIT, 2=SELBSTAENDIGKEIT, 4=AUSBILDUNG/Duales Studium, 34=Praktikum/Trainee).
     * @param string|null $fixedTerm Employment contract type (1=befristet, 2=unbefristet). Multiple values possible, separated by semicolon (e.g., "1;2").
     * @param string|null $workingHours Working time model (vz=VOLLZEIT, tz=TEILZEIT, snw=SCHICHT_NACHTARBEIT_WOCHENENDE, ho=HEIM_TELEARBEIT, mj=MINIJOB). Multiple values possible, separated by semicolon (e.g., "vz;tz").
     * @param bool|null $disability Include jobs suitable for people with disabilities.
     * @param bool|null $corona Include jobs related to Corona.
     * @param int|null $radius Search radius in kilometers from 'where' parameter (e.g., 25 or 200).
     * @return array Structured array containing job search results or error information.
     */
    public function __invoke(
        #[With(description: 'Free text search for job title.')]
        ?string $what = null,
        #[With(description: 'Free text search for employment location.')]
        ?string $where = null,
        #[With(description: 'Free text search for job field.')]
        ?string $jobField = null,
        #[With(minimum: 1)]
        int $page = 1,
        #[With(minimum: 1, maximum: 100)]
        int $size = 50,
        #[With(description: 'Employer name.')]
        ?string $employer = null,
        #[With(minimum: 0, maximum: 100)]
        ?int $publishedSince = null,
        bool $temporaryWork = true,
        #[With(enum: [1, 2, 4, 34])]
        ?int $offerType = null,
        #[With(pattern: '/^(1|2)(;(1|2))*$/', description: 'Semicolon-separated values: 1=befristet, 2=unbefristet.')]
        ?string $fixedTerm = null,
        #[With(pattern: '/^(vz|tz|snw|ho|mj)(;(vz|tz|snw|ho|mj))*$/', description: 'Semicolon-separated values: vz=VOLLZEIT, tz=TEILZEIT, snw=SCHICHT_NACHTARBEIT_WOCHENENDE, ho=HEIM_TELEARBEIT, mj=MINIJOB.')]
        ?string $workingHours = null,
        ?bool $disability = null,
        ?bool $corona = null,
        #[With(minimum: 1, maximum: 200)]
        ?int $radius = null,
    ): array {
        $this->logger->info('JobSearchTool execution started', compact(
            'what', 'where', 'jobField', 'page', 'size', 'employer', 'publishedSince',
            'temporaryWork', 'offerType', 'fixedTerm', 'workingHours', 'disability',
            'corona', 'radius'
        ));

        try {
            $queryParams = [];
            if ($what !== null) {
                $queryParams['was'] = $what;
            }
            if ($where !== null) {
                $queryParams['wo'] = $where;
            }
            if ($jobField !== null) {
                $queryParams['berufsfeld'] = $jobField;
            }
            $queryParams['page'] = $page;
            $queryParams['size'] = $size;
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
                $this->logger->info('JobSearchTool execution successful', ['statusCode' => $statusCode, 'resultSize' => count($content['stellenangebote'] ?? [])]);
                return [
                    'status' => 'success',
                    'data' => $content,
                ];
            } else {
                $this->logger->warning('API call failed', ['statusCode' => $statusCode, 'error' => $content]);
                return [
                    'status' => 'api_error',
                    'message' => 'API call returned an error.',
                    'statusCode' => $statusCode,
                    'details' => $content,
                ];
            }
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Network or HTTP client error', ['error' => $e->getMessage()]);
            return [
                'status' => 'network_error',
                'message' => 'Failed to connect to the job search API. Please check your internet connection or try again later.',
                'error' => $e->getMessage(),
            ];
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP protocol error', ['error' => $e->getMessage(), 'response' => $e->getResponse()->getContent(false)]);
            return [
                'status' => 'http_error',
                'message' => 'An HTTP error occurred during the API call.',
                'statusCode' => $e->getResponse()->getStatusCode(),
                'details' => $e->getResponse()->toArray(false),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during job search', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'status' => 'error',
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }
}
