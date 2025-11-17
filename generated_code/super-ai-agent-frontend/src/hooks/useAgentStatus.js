import { useState, useEffect, useCallback } from 'react';
import { pollAgentStatus } from '../api/agent';

/**
 * Custom Hook für Agent Status Polling
 * 
 * @param {string} sessionId - Session ID vom Agent
 * @param {number} pollInterval - Polling-Intervall in ms (default: 2000)
 * @returns {object} { statuses, completed, result, error, isPolling }
 */
export const useAgentStatus = (sessionId, pollInterval = 2000) => {
  const [statuses, setStatuses] = useState([]);
  const [completed, setCompleted] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [isPolling, setIsPolling] = useState(!!sessionId);
  const [lastTimestamp, setLastTimestamp] = useState(null);

  const fetchStatus = useCallback(async () => {
    if (!sessionId) return;

    try {
      const data = await pollAgentStatus(sessionId, lastTimestamp);
      
      // Update statuses (append new ones)
      if (data.statuses && data.statuses.length > 0) {
        setStatuses(prev => {
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
  }, [sessionId, lastTimestamp]);

  useEffect(() => {
    if (!isPolling || !sessionId) return;

    // Initial fetch
    fetchStatus();

    // Setup polling
    const interval = setInterval(fetchStatus, pollInterval);

    return () => clearInterval(interval);
  }, [isPolling, sessionId, pollInterval, fetchStatus]);

  const stopPolling = useCallback(() => {
    setIsPolling(false);
  }, []);

  const restartPolling = useCallback(() => {
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