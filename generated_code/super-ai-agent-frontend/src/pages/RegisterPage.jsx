import React, { useState } from 'react';
import { useNavigate, Link as RouterLink } from 'react-router-dom';
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
  InputAdornment,
  IconButton,
} from '@mui/material';
import { Visibility, VisibilityOff, PersonAdd, Google } from '@mui/icons-material';
import { useForm } from 'react-hook-form';
import { yupResolver } from '@hookform/resolvers/yup';
import * as yup from 'yup';
import { register as registerUser, getGoogleOAuthUrl } from '../api/auth';
import { getErrorMessage } from '../api/client';

/**
 * Validation Schema
 */
const schema = yup.object({
  email: yup.string().email('Ungültige E-Mail').required('E-Mail ist erforderlich'),
  password: yup
    .string()
    .min(8, 'Mindestens 8 Zeichen')
    .matches(/[A-Z]/, 'Mindestens ein Großbuchstabe')
    .matches(/[a-z]/, 'Mindestens ein Kleinbuchstabe')
    .matches(/[0-9]/, 'Mindestens eine Zahl')
    .required('Passwort ist erforderlich'),
  confirmPassword: yup
    .string()
    .oneOf([yup.ref('password')], 'Passwörter stimmen nicht überein')
    .required('Passwort-Bestätigung ist erforderlich'),
});

/**
 * Register Page Component
 */
const RegisterPage = () => {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: yupResolver(schema),
  });

  const onSubmit = async (data) => {
    try {
      setLoading(true);
      setError(null);

      await registerUser(data.email, data.password);
      
      setSuccess(true);
      setTimeout(() => {
        navigate('/login');
      }, 2000);
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const handleGoogleSignup = () => {
    window.location.href = getGoogleOAuthUrl();
  };

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
            <PersonAdd sx={{ fontSize: 60, color: 'primary.main', mb: 2 }} />
            <Typography variant="h4" gutterBottom>
              Konto erstellen
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Registriere dich für die AI Platform
            </Typography>
          </Box>

          {error && (
            <Alert severity="error" sx={{ mb: 3 }}>
              {error}
            </Alert>
          )}

          {success && (
            <Alert severity="success" sx={{ mb: 3 }}>
              Registrierung erfolgreich! Du wirst weitergeleitet...
            </Alert>
          )}

          <form onSubmit={handleSubmit(onSubmit)}>
            <TextField
              fullWidth
              label="E-Mail"
              type="email"
              margin="normal"
              {...register('email')}
              error={!!errors.email}
              helperText={errors.email?.message}
              disabled={loading || success}
            />

            <TextField
              fullWidth
              label="Passwort"
              type={showPassword ? 'text' : 'password'}
              margin="normal"
              {...register('password')}
              error={!!errors.password}
              helperText={errors.password?.message}
              disabled={loading || success}
              InputProps={{
                endAdornment: (
                  <InputAdornment position="end">
                    <IconButton
                      onClick={() => setShowPassword(!showPassword)}
                      edge="end"
                    >
                      {showPassword ? <VisibilityOff /> : <Visibility />}
                    </IconButton>
                  </InputAdornment>
                ),
              }}
            />

            <TextField
              fullWidth
              label="Passwort bestätigen"
              type={showPassword ? 'text' : 'password'}
              margin="normal"
              {...register('confirmPassword')}
              error={!!errors.confirmPassword}
              helperText={errors.confirmPassword?.message}
              disabled={loading || success}
            />

            <Button
              type="submit"
              fullWidth
              variant="contained"
              size="large"
              disabled={loading || success}
              sx={{ mt: 3, mb: 2 }}
            >
              {loading ? <CircularProgress size={24} /> : 'Registrieren'}
            </Button>

            {/* Divider */}
            <Box sx={{ display: 'flex', alignItems: 'center', my: 2 }}>
              <Box sx={{ flexGrow: 1, height: '1px', bgcolor: 'divider' }} />
              <Typography variant="body2" sx={{ px: 2, color: 'text.secondary' }}>
                oder
              </Typography>
              <Box sx={{ flexGrow: 1, height: '1px', bgcolor: 'divider' }} />
            </Box>

            {/* Google Signup Button */}
            <Button
              fullWidth
              variant="outlined"
              size="large"
              startIcon={<Google />}
              onClick={handleGoogleSignup}
              disabled={loading || success}
              sx={{ 
                mb: 2,
                textTransform: 'none',
                borderColor: '#4285f4',
                color: '#4285f4',
                '&:hover': {
                  borderColor: '#357ae8',
                  bgcolor: 'rgba(66, 133, 244, 0.04)',
                },
              }}
            >
              Mit Google registrieren
            </Button>

            <Box sx={{ textAlign: 'center' }}>
              <Typography variant="body2" color="text.secondary">
                Bereits ein Account?{' '}
                <Link
                  component={RouterLink}
                  to="/login"
                  sx={{ textDecoration: 'none' }}
                >
                  Jetzt anmelden
                </Link>
              </Typography>
            </Box>
          </form>
        </Paper>
      </Box>
    </Container>
  );
};

export default RegisterPage;