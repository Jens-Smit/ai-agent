import { useState, useEffect, useCallback } from 'react';
import { pollAgentStatus } from '../api/agent';

/**
 * Custom Hook für Agent Status Polling
 * * @param {string} sessionId - Session ID vom Agent
 * @param {number} pollInterval - Polling-Intervall in ms (default: 10000)
 * @returns {object} { statuses, completed, result, error, isPolling }
 */
export const useAgentStatus = (sessionId, pollInterval = 30000) => {
  const [statuses, setStatuses] = useState([]);
  const [completed, setCompleted] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [isPolling, setIsPolling] = useState(!!sessionId);
  // Speichern des letzten Zeitstempels als Date-Objekt, um es einfacher an die API zu übergeben
  const [lastTimestamp, setLastTimestamp] = useState(null);

  // Nutzen Sie useCallback, um sicherzustellen, dass fetchStatus nur neu erstellt wird, 
  // wenn sich die Abhängigkeiten ändern (sessionId, lastTimestamp).
  const fetchStatus = useCallback(async () => {
    if (!sessionId) return;

    try {
      // Da lastTimestamp ein Date-Objekt ist, muss es in ein Format gebracht werden,
      // das Ihr Backend erwartet (z.B. ISO-String oder c-Format, je nach pollAgentStatus Implementierung).
      // Wir gehen davon aus, dass pollAgentStatus das Date-Objekt korrekt verarbeitet.
      const data = await pollAgentStatus(sessionId, lastTimestamp);
      
      // Update statuses (append new ones)
      if (data.statuses && data.statuses.length > 0) {
        setStatuses(prev => {
          // Filterung gegen doppelte Einträge, obwohl die API getStatusesSince() liefert, 
          // ist dieser Filter eine gute Redundanz, falls der since-Zeitstempel ungenau war.
          const newStatuses = data.statuses.filter(
            s => !prev.some(existing => 
              existing.timestamp === s.timestamp && 
              existing.message === s.message
            )
          );
          return [...prev, ...newStatuses];
        });
        
        // Update last timestamp
        const latest = data.statuses[data.statuses.length - 1];
        if (latest?.timestamp) {
          // WICHTIG: Erstellen Sie ein neues Date-Objekt aus dem String
          setLastTimestamp(new Date(latest.timestamp));
        }
      }

      // Check completion
      if (data.completed) {
        setCompleted(true);
        setIsPolling(false);
        
        if (data.result) {
          setResult(data.result);
        }
        
        if (data.error) {
          setError(data.error);
        }
      }
    } catch (err) {
      console.error('Status polling failed:', err);
      setError(err.message || 'Status polling failed');
      setIsPolling(false);
    }
  }, [sessionId, lastTimestamp]); // Abhängigkeiten für useCallback

  useEffect(() => {
    if (!isPolling || !sessionId) return;

    // Initial fetch, um sofort den ersten Status zu erhalten
    fetchStatus();

    // Setup polling mit dem neuen Intervall
    const interval = setInterval(fetchStatus, pollInterval);

    // Cleanup-Funktion: Stoppt das Polling, wenn die Komponente unmounted oder Abhängigkeiten sich ändern
    return () => clearInterval(interval);
  }, [isPolling, sessionId, pollInterval, fetchStatus]);

  const stopPolling = useCallback(() => {
    setIsPolling(false);
  }, []);

  const restartPolling = useCallback(() => {
    // Beim Neustart sollte lastTimestamp zurückgesetzt werden, um alle Status erneut abzurufen.
    setLastTimestamp(null); 
    setCompleted(false);
    setError(null);
    setResult(null);
    setIsPolling(true);
  }, []);

  return {
    statuses,
    completed,
    result,
    error,
    isPolling,
    stopPolling,
    restartPolling
  };
};