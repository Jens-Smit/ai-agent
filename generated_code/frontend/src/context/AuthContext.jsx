import React, { createContext, useContext, useState, useEffect } from 'react';
import { getCurrentUser } from '../api/auth';

const AuthContext = createContext(null);

/**
 * Auth Context Provider
 * Verwaltet den globalen Authentifizierungsstatus
 */
export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  /**
   * Lädt den aktuellen Benutzer beim App-Start
   */
  useEffect(() => {
    checkAuth();
  }, []);

  /**
   * Prüft ob Benutzer authentifiziert ist (via Cookies)
   */
  const checkAuth = async () => {
    try {
      setLoading(true);
      
      // ✅ API-Call macht automatisch withCredentials=true
      // und sendet BEARER Cookie mit
      const data = await getCurrentUser();
      setUser(data.user || data); // Handle verschiedene Response-Formate
      setError(null);
    } catch (err) {
      console.log('Auth check failed:', err.message);
      setUser(null);
      setError(null); // Kein Error bei fehlender Auth
    } finally {
      setLoading(false);
    }
  };

  /**
   * Setzt Benutzer nach erfolgreichem Login
   */
  const loginUser = (userData) => {
    setUser(userData);
    setError(null);
  };

  /**
   * Entfernt Benutzer nach Logout
   */
  const logoutUser = () => {
    setUser(null);
    setError(null);
    setLoading(false);
  };

  /**
   * Update User Profile
   */
  const updateUser = (userData) => {
    setUser(userData);
  };

  const value = {
    user,
    loading,
    error,
    isAuthenticated: !!user,
    loginUser,
    logoutUser,
    updateUser,
    checkAuth,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

/**
 * Custom Hook für Auth Context
 */
export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
};