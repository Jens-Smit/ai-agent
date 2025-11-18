import apiClient from './client';

/**
 * AI Agent API Service - Updated für Async Jobs mit Status Polling
 */

/**
 * Personal Assistant - POST /api/agent
 * Returns sessionId for status polling
 */
export const callPersonalAssistant = async (prompt) => {
  const response = await apiClient.post('/api/agent', { prompt }, {
    timeout: 10000, // Kurzes Timeout, da Job sofort zurückkehrt
  });
  return response.data;
};

/**
 * Development Agent - POST /api/devAgent
 */
export const callDevAgent = async (prompt) => {
  const response = await apiClient.post('/api/devAgent', { prompt }, {
    timeout: 10000,
  });
  return response.data;
};

/**
 * Frontend Generator Agent - POST /api/frondend_devAgent
 */
export const callFrontendDevAgent = async (prompt) => {
  const response = await apiClient.post('/api/frondend_devAgent', { prompt }, {
    timeout: 10000,
  });
  return response.data;
};

/**
 * NEU: Poll Agent Status
 * GET /api/agent/status/{sessionId}
 */
export const pollAgentStatus = async (sessionId, since = null) => {
  const params = since ? { since: since.toISOString() } : {};
  const response = await apiClient.get(`/api/agent/status/${sessionId}`, { params });
  return response.data;
};

/**
 * Index Knowledge Base
 */
export const indexKnowledgeBase = async () => {
  const response = await apiClient.post('/api/index-knowledge');
  return response.data;
};