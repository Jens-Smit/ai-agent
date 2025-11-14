import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Box, Container, CircularProgress, Typography, Alert } from '@mui/material';
import { Google } from '@mui/icons-material';
import { handleGoogleCallback } from '../api/auth';
import { useAuth } from '../context/AuthContext';
import { getErrorMessage } from '../api/client';

/**
 * Google OAuth Callback Page
 * Wird nach erfolgreicher Google-Authentifizierung aufgerufen
 */
const GoogleCallbackPage = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { loginUser } = useAuth();
  const [error, setError] = useState(null);
  const [processing, setProcessing] = useState(true);

  useEffect(() => {
    const processCallback = async () => {
      try {
        // Check for error in URL params (OAuth error)
        const errorParam = searchParams.get('error');
        if (errorParam) {
          setError('Google Login wurde abgebrochen oder fehlgeschlagen');
          setProcessing(false);
          setTimeout(() => navigate('/login'), 3000);
          return;
        }

        // Der Backend-Authenticator sollte den Cookie bereits gesetzt haben
        // Wir holen jetzt nur noch die User-Daten
        const data = await handleGoogleCallback();
        
        if (data.user) {
          loginUser(data.user);
          navigate('/dashboard');
        } else {
          throw new Error('Keine Benutzerdaten erhalten');
        }
      } catch (err) {
        console.error('Google Callback Error:', err);
        setError(getErrorMessage(err));
        setProcessing(false);
        setTimeout(() => navigate('/login'), 3000);
      }
    };

    processCallback();
  }, [navigate, loginUser, searchParams]);

  return (
    <Container maxWidth="sm">
      <Box
        sx={{
          minHeight: '100vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Box sx={{ textAlign: 'center' }}>
          <Google sx={{ fontSize: 80, color: '#4285f4', mb: 3 }} />
          
          {processing && !error && (
            <>
              <CircularProgress size={60} sx={{ mb: 3 }} />
              <Typography variant="h5" gutterBottom>
                Google Login wird verarbeitet...
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Bitte warten Sie einen Moment
              </Typography>
            </>
          )}

          {error && (
            <>
              <Alert severity="error" sx={{ mb: 3, textAlign: 'left' }}>
                {error}
              </Alert>
              <Typography variant="body2" color="text.secondary">
                Sie werden in Kürze zur Login-Seite weitergeleitet...
              </Typography>
            </>
          )}
        </Box>
      </Box>
    </Container>
  );
};

export default GoogleCallbackPage;