<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result;
use App\Entity\WorkflowStep;

/**
 * Fix für die executeCompanyContactFinderWithFallback Methode im WorkflowExecutor
 * 
 * Diese Methode sollte das 'success' Flag im Tool-Ergebnis prüfen,
 * nicht nur ob 'application_email' gefüllt ist.
 */
class WorkflowExecutorContactFinderFix
{
    private LoggerInterface $logger;
    
    /**
     * ALTE (fehlerhafte) Version - NUR ALS REFERENZ
     * 
     * private function executeCompanyContactFinderWithFallback(
     *     WorkflowStep $step,
     *     array $context,
     *     array $resolvedParameters,
     *     string $sessionId
     * ): array {
     *     // ... Code ...
     *     
     *     // ❌ FALSCH: Prüft nur application_email
     *     if (empty($result['application_email'])) {
     *         throw new \RuntimeException("Keine Bewerbungs-E-Mail gefunden");
     *     }
     * }
     */
    
    /**
     * NEUE (korrekte) Version
     * 
     * Führt das company_career_contact_finder Tool mit Fallback-Logik aus.
     * Akzeptiert das Ergebnis wenn:
     * - Das 'success' Flag true ist, ODER
     * - Mindestens eine E-Mail (application_email ODER general_email) gefunden wurde
     */
    private function executeCompanyContactFinderWithFallback(
        WorkflowStep $step,
        array $context,
        array $resolvedParameters,
        string $sessionId
    ): array {
        $maxAttempts = 1; // Kann auf 2-3 erhöht werden für mehrere Versuche
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            $this->logger->info(
                sprintf('🔄 Starte Kontakt-Suche (Versuch %d): Initialer Firmenname: %s', 
                    $attempt, 
                    $resolvedParameters['company_name'] ?? 'unbekannt'
                )
            );
            
            $this->entityManager->flush();
            
            try {
                // Führe das Tool aus
                $result = $this->agentService->call(
                    $sessionId,
                    $step->getToolName(),
                    $resolvedParameters
                );
                
                // Prüfe auf erfolgreiche Ausführung
                if ($this->isContactFinderSuccessful($result)) {
                    $this->logger->info(
                        '✅ Kontaktdaten erfolgreich gefunden',
                        [
                            'application_email' => $result['application_email'] ?? null,
                            'general_email' => $result['general_email'] ?? null,
                            'contact_person' => $result['contact_person'] ?? null,
                            'career_page_url' => $result['career_page_url'] ?? null,
                        ]
                    );
                    
                    return $result;
                }
                
                // Tool war nicht erfolgreich
                $lastError = sprintf(
                    'Tool lieferte keine verwertbaren Kontaktdaten (success=%s, application_email=%s, general_email=%s)',
                    var_export($result['success'] ?? false, true),
                    $result['application_email'] ?? 'null',
                    $result['general_email'] ?? 'null'
                );
                
                $this->logger->warning(
                    sprintf('⚠️ Kontaktsuche fehlgeschlagen. %s', 
                        $attempt < $maxAttempts ? 'Versuche nächsten Fallback.' : 'Keine weiteren Versuche.'
                    ),
                    ['attempt' => $attempt, 'error' => $lastError]
                );
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $this->logger->warning(
                    sprintf('Tool-Ausführung fehlgeschlagen bei Versuch %d: %s', $attempt, $e->getMessage()),
                    ['exception' => $e]
                );
            }
            
            // Optional: Fallback-Strategie für weitere Versuche
            if ($attempt < $maxAttempts) {
                // Hier könnte man den Firmennamen modifizieren, z.B.:
                // - Entferne "GmbH", "AG", etc.
                // - Versuche alternative Schreibweisen
                // - Füge "Karriere" oder "Jobs" hinzu
                
                $companyName = $resolvedParameters['company_name'] ?? '';
                
                // Beispiel: Entferne Rechtsformen für zweiten Versuch
                if ($attempt === 1 && preg_match('/(.+?)\s+(GmbH|AG|e\.K\.|KG|UG|SE|mbH)/i', $companyName, $matches)) {
                    $resolvedParameters['company_name'] = trim($matches[1]);
                    $this->logger->info(
                        sprintf('🔄 Versuche mit vereinfachtem Firmennamen: %s', $resolvedParameters['company_name'])
                    );
                }
            }
        }
        
        // Alle Versuche fehlgeschlagen
        $errorMessage = sprintf(
            'Kontaktdaten konnten nach %d Versuchen nicht gefunden werden. Der Workflow kann nicht fortgesetzt werden.',
            $maxAttempts
        );
        
        if ($lastError) {
            $errorMessage .= sprintf(' Letzter Fehler: %s', $lastError);
        }
        
        throw new \RuntimeException($errorMessage);
    }
    
    /**
     * Prüft, ob das Ergebnis des ContactFinder-Tools als erfolgreich gilt.
     * 
     * Erfolgreich bedeutet:
     * 1. Das 'success' Flag ist explizit auf true gesetzt, ODER
     * 2. Mindestens eine E-Mail wurde gefunden (application_email ODER general_email)
     * 
     * @param array $result Das Ergebnis vom Tool
     * @return bool True wenn erfolgreich, sonst false
     */
    private function isContactFinderSuccessful(array $result): bool
    {
        // Prüfe explizites success-Flag
        if (isset($result['success']) && $result['success'] === true) {
            $this->logger->debug('✅ Tool meldet explizit success=true');
            return true;
        }
        
        // Fallback: Prüfe ob mindestens eine E-Mail gefunden wurde
        $hasApplicationEmail = !empty($result['application_email']);
        $hasGeneralEmail = !empty($result['general_email']);
        
        if ($hasApplicationEmail || $hasGeneralEmail) {
            $this->logger->debug(
                '✅ Mindestens eine E-Mail gefunden',
                [
                    'application_email' => $hasApplicationEmail,
                    'general_email' => $hasGeneralEmail,
                ]
            );
            return true;
        }
        
        // Keine verwertbaren Daten gefunden
        $this->logger->debug('❌ Keine erfolgreichen Kontaktdaten im Ergebnis');
        return false;
    }
    
    /**
     * Optional: Validiere und bereinige die gefundenen Kontaktdaten
     */
    private function validateContactData(array $result): array
    {
        // Entferne ungültige contact_person Einträge
        if (isset($result['contact_person'])) {
            $invalidNames = [
                'social media',
                'für nachunternehmer',
                'für lieferanten',
                'kontakt',
                'impressum',
                'team',
            ];
            
            $contactPerson = strtolower($result['contact_person']);
            foreach ($invalidNames as $invalid) {
                if (str_contains($contactPerson, $invalid)) {
                    $this->logger->debug(
                        sprintf('⚠️ Ungültiger contact_person entfernt: "%s"', $result['contact_person'])
                    );
                    $result['contact_person'] = null;
                    break;
                }
            }
        }
        
        // Validiere E-Mail-Adressen
        if (isset($result['application_email']) && !filter_var($result['application_email'], FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning(
                sprintf('⚠️ Ungültige application_email: "%s"', $result['application_email'])
            );
            $result['application_email'] = null;
        }
        
        if (isset($result['general_email']) && !filter_var($result['general_email'], FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning(
                sprintf('⚠️ Ungültige general_email: "%s"', $result['general_email'])
            );
            $result['general_email'] = null;
        }
        
        return $result;
    }
}

/**
 * ZUSAMMENFASSUNG DER ÄNDERUNGEN:
 * 
 * 1. ✅ Neue Methode `isContactFinderSuccessful()`:
 *    - Prüft das 'success' Flag im Tool-Ergebnis
 *    - Fallback: Akzeptiert auch wenn nur general_email vorhanden ist
 * 
 * 2. ✅ Verbesserte Fehlerbehandlung:
 *    - Detailliertes Logging was genau fehlt
 *    - Optionale Fallback-Strategie mit modifiziertem Firmennamen
 * 
 * 3. ✅ Optional: Validierung der Ergebnisse:
 *    - Filtert ungültige contact_person Werte wie "Social media"
 *    - Validiert E-Mail-Adressen
 * 
 * ANWENDUNG IN IHREM CODE:
 * - Ersetzen Sie die bestehende executeCompanyContactFinderWithFallback() Methode
 * - Fügen Sie die isContactFinderSuccessful() Hilfsmethode hinzu
 * - Optional: validateContactData() für zusätzliche Validierung
 */