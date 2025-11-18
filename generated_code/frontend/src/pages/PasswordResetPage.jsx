import React, { useState } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router-dom';
import {
  Box,
  Container,
  Paper,
  TextField,
  Button,
  Typography,
  Link,
  Alert,
  CircularProgress,
  Stepper,
  Step,
  StepLabel,
} from '@mui/material';
import { LockReset, Email, CheckCircle } from '@mui/icons-material';
import { useForm } from 'react-hook-form';
import { yupResolver } from '@hookform/resolvers/yup';
import * as yup from 'yup';
import { requestPasswordReset, resetPassword } from '../api/auth';
import { getErrorMessage } from '../api/client';

/**
 * Request Reset Schema
 */
const requestSchema = yup.object({
  email: yup.string().email('Ungültige E-Mail').required('E-Mail ist erforderlich'),
});

/**
 * Reset Password Schema
 */
const resetSchema = yup.object({
  newPassword: yup
    .string()
    .min(8, 'Mindestens 8 Zeichen')
    .matches(/[A-Z]/, 'Mindestens ein Großbuchstabe')
    .matches(/[a-z]/, 'Mindestens ein Kleinbuchstabe')
    .matches(/[0-9]/, 'Mindestens eine Zahl')
    .required('Neues Passwort ist erforderlich'),
  confirmPassword: yup
    .string()
    .oneOf([yup.ref('newPassword')], 'Passwörter stimmen nicht überein')
    .required('Passwort-Bestätigung ist erforderlich'),
});

/**
 * Password Reset Page Component
 */
const PasswordResetPage = () => {
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(false);
  const [step, setStep] = useState(token ? 1 : 0);

  const {
    register: registerRequest,
    handleSubmit: handleSubmitRequest,
    formState: { errors: requestErrors },
  } = useForm({
    resolver: yupResolver(requestSchema),
  });

  const {
    register: registerReset,
    handleSubmit: handleSubmitReset,
    formState: { errors: resetErrors },
  } = useForm({
    resolver: yupResolver(resetSchema),
  });

  const handleRequestReset = async (data) => {
    try {
      setLoading(true);
      setError(null);

      await requestPasswordReset(data.email);

      setSuccess(true);
      setStep(1);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (data) => {
    try {
      setLoading(true);
      setError(null);

      await resetPassword(token, data.newPassword);

      setSuccess(true);
      setStep(2);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const steps = ['E-Mail eingeben', 'Link aus E-Mail öffnen', 'Neues Passwort setzen'];

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
        <Paper elevation={3} sx={{ p: 4, width: '100%' }}>
          <Box sx={{ textAlign: 'center', mb: 3 }}>
            <LockReset sx={{ fontSize: 60, color: 'primary.main', mb: 2 }} />
            <Typography variant="h4" gutterBottom>
              Passwort zurücksetzen
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {!token
                ? 'Gib deine E-Mail-Adresse ein'
                : 'Setze dein neues Passwort'}
            </Typography>
          </Box>

          {/* Stepper */}
          <Stepper activeStep={step} sx={{ mb: 4 }}>
            {steps.map((label) => (
              <Step key={label}>
                <StepLabel>{label}</StepLabel>
              </Step>
            ))}
          </Stepper>

          {error && (
            <Alert severity="error" sx={{ mb: 3 }}>
              {error}
            </Alert>
          )}

          {success && step === 1 && (
            <Alert severity="success" sx={{ mb: 3 }} icon={<Email />}>
              Wir haben dir eine E-Mail mit einem Reset-Link gesendet.
              Bitte überprüfe dein Postfach.
            </Alert>
          )}

          {success && step === 2 && (
            <Box sx={{ textAlign: 'center' }}>
              <Alert severity="success" sx={{ mb: 3 }} icon={<CheckCircle />}>
                Passwort erfolgreich zurückgesetzt!
              </Alert>
              <Button
                component={RouterLink}
                to="/login"
                variant="contained"
                fullWidth
              >
                Zum Login
              </Button>
            </Box>
          )}

          {/* Request Reset Form */}
          {!token && !success && (
            <form onSubmit={handleSubmitRequest(handleRequestReset)}>
              <TextField
                fullWidth
                label="E-Mail"
                type="email"
                margin="normal"
                {...registerRequest('email')}
                error={!!requestErrors.email}
                helperText={requestErrors.email?.message}
                disabled={loading}
              />

              <Button
                type="submit"
                fullWidth
                variant="contained"
                size="large"
                disabled={loading}
                sx={{ mt: 3, mb: 2 }}
              >
                {loading ? <CircularProgress size={24} /> : 'Reset-Link senden'}
              </Button>

              <Box sx={{ textAlign: 'center' }}>
                <Link
                  component={RouterLink}
                  to="/login"
                  sx={{ textDecoration: 'none' }}
                >
                  Zurück zum Login
                </Link>
              </Box>
            </form>
          )}

          {/* Reset Password Form */}
          {token && !success && (
            <form onSubmit={handleSubmitReset(handleResetPassword)}>
              <TextField
                fullWidth
                type="password"
                label="Neues Passwort"
                margin="normal"
                {...registerReset('newPassword')}
                error={!!resetErrors.newPassword}
                helperText={resetErrors.newPassword?.message}
                disabled={loading}
              />

              <TextField
                fullWidth
                type="password"
                label="Passwort bestätigen"
                margin="normal"
                {...registerReset('confirmPassword')}
                error={!!resetErrors.confirmPassword}
                helperText={resetErrors.confirmPassword?.message}
                disabled={loading}
              />

              <Button
                type="submit"
                fullWidth
                variant="contained"
                size="large"
                disabled={loading}
                sx={{ mt: 3, mb: 2 }}
              >
                {loading ? <CircularProgress size={24} /> : 'Passwort zurücksetzen'}
              </Button>

              <Box sx={{ textAlign: 'center' }}>
                <Link
                  component={RouterLink}
                  to="/login"
                  sx={{ textDecoration: 'none' }}
                >
                  Zurück zum Login
                </Link>
              </Box>
            </form>
          )}
        </Paper>
      </Box>
    </Container>
  );
};

export default PasswordResetPage;