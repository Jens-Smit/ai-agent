import apiClient from './client';

/**
 * AI Agent API Service
 * Endpunkte für alle AI Agents
 */

/**
 * Personal Assistant - POST /api/agent
 * @param {string} prompt 
 * @returns {Promise<{status: string, response: string, metadata: object}>}
 */
export const callPersonalAssistant = async (prompt) => {
  const response = await apiClient.post('/api/agent', { prompt }, {
    timeout: 120000, // 2 Minuten für AI-Antworten
  });
  return response.data;
};

/**
 * Development Agent - POST /api/devAgent
 * @param {string} prompt 
 * @returns {Promise<{status: string, ai_response: string, files_created?: array, deployment_instructions?: string}>}
 */
export const callDevAgent = async (prompt) => {
  const response = await apiClient.post('/api/devAgent', { prompt }, {
    timeout: 300000, // 5 Minuten für Code-Generierung
  });
  return response.data;
};

/**
 * Frontend Generator Agent - POST /api/frondend_devAgent
 * @param {string} prompt 
 * @returns {Promise<{status: string, ai_response: string, files_created?: array, deployment_instructions?: string}>}
 */
export const callFrontendDevAgent = async (prompt) => {
  const response = await apiClient.post('/api/frondend_devAgent', { prompt }, {
    timeout: 300000, // 5 Minuten für Frontend-Generierung
  });
  return response.data;
};

/**
 * Index Knowledge Base - POST /api/index-knowledge
 * @returns {Promise<{status: string, message: string, details: string}>}
 */
export const indexKnowledgeBase = async () => {
  const response = await apiClient.post('/api/index-knowledge');
  return response.data;
};