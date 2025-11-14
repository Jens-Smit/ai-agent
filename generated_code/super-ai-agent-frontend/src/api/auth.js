import apiClient from './client';

/**
 * Authentication API Service
 * Alle Endpunkte für Authentifizierung
 */

/**
 * Login - POST /api/login
 * @param {string} email 
 * @param {string} password 
 * @returns {Promise<{message: string, user: object}>}
 */
export const login = async (email, password) => {
  const response = await apiClient.post('/api/login', { email, password });
  return response.data;
};

/**
 * Logout - POST /api/logout
 * @returns {Promise<{message: string}>}
 */
export const logout = async () => {
  const response = await apiClient.post('/api/logout');
  return response.data;
};

/**
 * Register - POST /api/register
 * @param {string} email 
 * @param {string} password 
 * @returns {Promise<{message: string, user: object}>}
 */
export const register = async (email, password) => {
  const response = await apiClient.post('/api/register', { email, password });
  return response.data;
};

/**
 * Request Password Reset - POST /api/password/request-reset
 * @param {string} email 
 * @returns {Promise<{message: string}>}
 */
export const requestPasswordReset = async (email) => {
  const response = await apiClient.post('/api/password/request-reset', { email });
  return response.data;
};

/**
 * Reset Password - POST /api/password/reset
 * @param {string} token 
 * @param {string} newPassword 
 * @returns {Promise<{message: string}>}
 */
export const resetPassword = async (token, newPassword) => {
  const response = await apiClient.post('/api/password/reset', { token, newPassword });
  return response.data;
};

/**
 * Change Password - POST /api/password/change
 * @param {string} currentPassword 
 * @param {string} newPassword 
 * @returns {Promise<{message: string}>}
 */
export const changePassword = async (currentPassword, newPassword) => {
  const response = await apiClient.post('/api/password/change', { 
    currentPassword, 
    newPassword 
  });
  return response.data;
};

/**
 * Get Current User - GET /api/user
 * @returns {Promise<{user: object}>}
 */
export const getCurrentUser = async () => {
  const response = await apiClient.get('/api/user');
  return response.data;
};

/**
 * Update User Profile - PUT /api/user
 * @param {object} userData 
 * @returns {Promise<{message: string, user: object}>}
 */
export const updateUserProfile = async (userData) => {
  const response = await apiClient.put('/api/user', userData);
  return response.data;
};

/**
 * Google OAuth Login - Initiates Google OAuth flow
 * @returns {string} Google OAuth URL
 */
export const getGoogleOAuthUrl = () => {
  const backendUrl = process.env.REACT_APP_API_URL?.replace('/api', '') || 'http://127.0.0.1:8000';
  return `${backendUrl}/connect/google`;
};

/**
 * Handle Google OAuth Callback
 * Called after redirect from Google
 * @returns {Promise<{user: object}>}
 */
export const handleGoogleCallback = async () => {
  const response = await apiClient.get('/api/user');
  return response.data;
};