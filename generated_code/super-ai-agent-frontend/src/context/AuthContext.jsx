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
   * Prüft ob Benutzer authentifiziert ist
   */
  const checkAuth = async () => {
    const token = localStorage.getItem('access_token'); // oder Cookie
    if (!token) {
      setUser(null);
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      const data = await getCurrentUser();
      setUser(data.user);
    } catch (err) {
      setUser(null);
    } finally {
      setLoading(false);
    }
  };


  /**
   * Setzt Benutzer nach erfolgreichem Login
   */
  const loginUser = (userData) => {
    setUser(userData);
  };

  /**
   * Entfernt Benutzer nach Logout
   */
  const logoutUser = () => {
    localStorage.removeItem('access_token');
    setUser(null);
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