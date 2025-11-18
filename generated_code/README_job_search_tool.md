# JobSearchTool

Das `JobSearchTool` ermöglicht die Suche nach Jobs und offenen Stellen über die [jobsuche.api.bund.dev API](https://jobsuche.api.bund.dev/). Es bietet flexible Suchoptionen basierend auf Stichworten, Orten, Seitennummerierung und Sortierkriterien.

## Einsatzmöglichkeiten

Dieses Tool kann verwendet werden, um:

- Spezifische Jobangebote zu finden.
- Offene Stellen in bestimmten Regionen zu identifizieren.
- Ergebnisse nach Relevanz oder Datum zu sortieren.

## Parameter

Das `__invoke` Methode akzeptiert die folgenden Parameter:

- `was` (string, optional): Ein Suchbegriff für die Stellenbezeichnung (z.B. "Softwareentwickler", "Projektmanager").
- `wo` (string, optional): Ein Suchbegriff für den Ort oder die Region (z.B. "Berlin", "Homeoffice", "Bayern").
- `seite` (int, optional, Standard: `0`): Die Seitenzahl der Suchergebnisse. Muss eine nicht-negative Ganzzahl sein.
- `size` (int, optional, Standard: `10`): Die Anzahl der Ergebnisse pro Seite. Der Wert muss zwischen `1` und `50` liegen.
- `sort` (string, optional, Standard: `date`): Die Sortierreihenfolge der Ergebnisse. Akzeptierte Werte sind `date` (nach Datum) oder `relevance` (nach Relevanz).

## Rückgabeformat

Das Tool gibt ein Array mit den folgenden Schlüsseln zurück:

- `status` (string): Zeigt den Status der Operation an (`success` oder `error`).
- `data` (array, optional): Enthält die vollständige API-Antwort im Erfolgsfall. Dies ist ein strukturiertes Array, das `hits` mit `total` (Gesamtzahl der Ergebnisse) und einer Liste der Jobangebote enthält.
- `message` (string, optional): Eine beschreibende Fehlermeldung im Fehlerfall.
- `details` (array, optional): Zusätzliche Details zur Fehlermeldung, falls von der API zurückgegeben.

## Beispiel für die Nutzung

```php
use App\Tool\JobSearchTool;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MyAgentUseCases
{
    public function __construct(
        private JobSearchTool $jobSearchTool,
        private LoggerInterface $logger
    ) {}

    public function searchForSoftwareJobsInBerlin(): array
    {
        $this->logger->info('Suche nach Software-Jobs in Berlin...');
        $results = ($this->jobSearchTool)('Softwareentwickler', 'Berlin', 0, 20, 'relevance');

        if ($results['status'] === 'success') {
            $this->logger->info(sprintf('Gefundene Jobs: %d', $results['data']['hits']['total']));
            foreach ($results['data']['hits']['hits'] as $job) {
                $this->logger->info(sprintf('- %s in %s', $job['title'], $job['location']));
            }
        } else {
            $this->logger->error(sprintf('Fehler bei der Jobsuche: %s', $results['message']));
        }

        return $results;
    }
}
```