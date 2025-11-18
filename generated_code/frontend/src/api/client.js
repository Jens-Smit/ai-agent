import axios from 'axios';

const API_BASE_URL = process.env.REACT_APP_API_URL || 'https://127.0.0.1:8000';

/**
 * Axios instance mit automatischer Token-Behandlung
 * Tokens werden in httpOnly Cookies gespeichert und automatisch vom Browser gesendet
 */
const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30000,
  withCredentials: true, // WICHTIG: Sendet Cookies mit jedem Request
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

/**
 * Request Interceptor - Logging und Error Prevention
 */
apiClient.interceptors.request.use(
  (config) => {
    console.log(`[API] ${config.method.toUpperCase()} ${config.url}`, config.data);
    return config;
  },
  (error) => {
    console.error('[API Request Error]', error);
    return Promise.reject(error);
  }
);

/**
 * Response Interceptor - Token Refresh & Error Handling
 */
apiClient.interceptors.response.use(
  (response) => {
    console.log(`[API Success] ${response.config.url}`, response.data);
    return response;
  },
  async (error) => {
    const originalRequest = error.config;

    // Token abgelaufen - versuche Refresh
    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        console.log('[API] Attempting token refresh...');
        await axios.post(
          `${API_BASE_URL}/api/token/refresh`,
          {},
          { withCredentials: true }
        );
        
        console.log('[API] Token refresh successful, retrying original request');
        return apiClient(originalRequest);
      } catch (refreshError) {
        console.error('[API] Token refresh failed', refreshError);
        
        // Redirect to login
        if (window.location.pathname !== '/api/login') {
          window.location.href = '/api/login';
        }
        
        return Promise.reject(refreshError);
      }
    }

    // Rate Limiting
    if (error.response?.status === 429) {
      const retryAfter = error.response.data.retry_after || 60;
      console.warn(`[API] Rate limited. Retry after ${retryAfter}s`);
    }

    console.error('[API Error]', {
      status: error.response?.status,
      message: error.response?.data?.message || error.message,
      url: error.config?.url,
    });

    return Promise.reject(error);
  }
);

/**
 * Helper: Extrahiert Error Message aus verschiedenen Response-Formaten
 */
export const getErrorMessage = (error) => {
  if (error.response?.data?.message) {
    return error.response.data.message;
  }
  if (error.response?.data?.error) {
    return error.response.data.error;
  }
  if (error.response?.data?.violations) {
    return error.response.data.violations.join(', ');
  }
  if (error.message) {
    return error.message;
  }
  return 'Ein unbekannter Fehler ist aufgetreten';
};

/**
 * Helper: Prüft ob Benutzer authentifiziert ist
 */
export const isAuthenticated = async () => {
  try {
    const response = await apiClient.get('/api/user');
    return response.status === 200;
  } catch {
    return false;
  }
};

export default apiClient;