import React, { useState } from 'react';
import {
  Box,
  Paper,
  Typography,
  TextField,
  Button,
  Alert,
  CircularProgress,
  Divider,
  Avatar,
  Grid,
} from '@mui/material';
import { Person, Lock, Save } from '@mui/icons-material';
import { useForm } from 'react-hook-form';
import { yupResolver } from '@hookform/resolvers/yup';
import * as yup from 'yup';
import { useAuth } from '../context/AuthContext';
import { updateUserProfile, changePassword } from '../api/auth';
import { getErrorMessage } from '../api/client';

/**
 * Password Change Schema
 */
const passwordSchema = yup.object({
  currentPassword: yup.string().required('Aktuelles Passwort ist erforderlich'),
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
 * Profile Page Component
 */
const ProfilePage = () => {
  const { user, updateUser } = useAuth();
  const [loadingProfile, setLoadingProfile] = useState(false);
  const [loadingPassword, setLoadingPassword] = useState(false);
  const [successProfile, setSuccessProfile] = useState(null);
  const [successPassword, setSuccessPassword] = useState(null);
  const [errorProfile, setErrorProfile] = useState(null);
  const [errorPassword, setErrorPassword] = useState(null);

  const {
    register: registerPassword,
    handleSubmit: handleSubmitPassword,
    formState: { errors: passwordErrors },
    reset: resetPassword,
  } = useForm({
    resolver: yupResolver(passwordSchema),
  });

  const handlePasswordChange = async (data) => {
    try {
      setLoadingPassword(true);
      setErrorPassword(null);
      setSuccessPassword(null);

      await changePassword(data.currentPassword, data.newPassword);

      setSuccessPassword('Passwort erfolgreich geändert');
      resetPassword();
    } catch (err) {
      setErrorPassword(getErrorMessage(err));
    } finally {
      setLoadingPassword(false);
    }
  };

  return (
    <Box>
      {/* Header */}
      <Paper sx={{ p: 3, mb: 4 }}>
        <Box sx={{ display: 'flex', alignItems: 'center' }}>
          <Avatar sx={{ width: 80, height: 80, bgcolor: 'primary.main', mr: 3 }}>
            <Person sx={{ fontSize: 50 }} />
          </Avatar>
          <Box>
            <Typography variant="h4" gutterBottom>
              Mein Profil
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Verwalte deine Account-Einstellungen
            </Typography>
          </Box>
        </Box>
      </Paper>

      <Grid container spacing={3}>
        {/* Account Information */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3 }}>
            <Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center' }}>
              <Person sx={{ mr: 1 }} />
              Account-Informationen
            </Typography>
            <Divider sx={{ mb: 3 }} />

            {errorProfile && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {errorProfile}
              </Alert>
            )}

            {successProfile && (
              <Alert severity="success" sx={{ mb: 2 }}>
                {successProfile}
              </Alert>
            )}

            <TextField
              fullWidth
              label="E-Mail"
              value={user?.email || ''}
              disabled
              margin="normal"
              helperText="E-Mail kann nicht geändert werden"
            />

            {user?.name && (
              <TextField
                fullWidth
                label="Name"
                value={user.name}
                disabled
                margin="normal"
              />
            )}

            {user?.googleId && (
              <TextField
                fullWidth
                label="Verbunden mit"
                value="Google Account"
                disabled
                margin="normal"
                helperText="Dieser Account ist mit Google verknüpft"
              />
            )}

            <TextField
              fullWidth
              label="Registriert seit"
              value={user?.createdAt ? new Date(user.createdAt).toLocaleDateString('de-DE') : 'N/A'}
              disabled
              margin="normal"
            />

            <TextField
              fullWidth
              label="Rollen"
              value={user?.roles?.join(', ') || 'ROLE_USER'}
              disabled
              margin="normal"
            />
          </Paper>
        </Grid>

        {/* Password Change */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3 }}>
            <Typography variant="h6" gutterBottom sx={{ display: 'flex', alignItems: 'center' }}>
              <Lock sx={{ mr: 1 }} />
              Passwort ändern
            </Typography>
            <Divider sx={{ mb: 3 }} />

            {user?.googleId && (
              <Alert severity="info" sx={{ mb: 2 }}>
                Dieser Account ist mit Google verknüpft. Du kannst dein Passwort über Google verwalten.
              </Alert>
            )}

            {errorPassword && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {errorPassword}
              </Alert>
            )}

            {successPassword && (
              <Alert severity="success" sx={{ mb: 2 }}>
                {successPassword}
              </Alert>
            )}

            {!user?.googleId ? (
              <form onSubmit={handleSubmitPassword(handlePasswordChange)}>
                <TextField
                  fullWidth
                  type="password"
                  label="Aktuelles Passwort"
                  margin="normal"
                  {...registerPassword('currentPassword')}
                  error={!!passwordErrors.currentPassword}
                  helperText={passwordErrors.currentPassword?.message}
                  disabled={loadingPassword}
                />

                <TextField
                  fullWidth
                  type="password"
                  label="Neues Passwort"
                  margin="normal"
                  {...registerPassword('newPassword')}
                  error={!!passwordErrors.newPassword}
                  helperText={passwordErrors.newPassword?.message}
                  disabled={loadingPassword}
                />

                <TextField
                  fullWidth
                  type="password"
                  label="Neues Passwort bestätigen"
                  margin="normal"
                  {...registerPassword('confirmPassword')}
                  error={!!passwordErrors.confirmPassword}
                  helperText={passwordErrors.confirmPassword?.message}
                  disabled={loadingPassword}
                />

                <Button
                  type="submit"
                  fullWidth
                  variant="contained"
                  startIcon={loadingPassword ? <CircularProgress size={20} /> : <Save />}
                  disabled={loadingPassword}
                  sx={{ mt: 2 }}
                >
                  {loadingPassword ? 'Wird gespeichert...' : 'Passwort ändern'}
                </Button>
              </form>
            ) : (
              <Box sx={{ textAlign: 'center', py: 3 }}>
                <Typography variant="body2" color="text.secondary">
                  Passwortänderungen werden über deinen Google Account verwaltet.
                </Typography>
              </Box>
            )}
          </Paper>
        </Grid>

        {/* Security Information */}
        <Grid item xs={12}>
          <Paper sx={{ p: 3 }}>
            <Typography variant="h6" gutterBottom>
              Sicherheits-Informationen
            </Typography>
            <Divider sx={{ mb: 2 }} />
            <Grid container spacing={2}>
              <Grid item xs={12} sm={6}>
                <Typography variant="subtitle2" color="text.secondary">
                  Authentifizierung
                </Typography>
                <Typography variant="body1">JWT + httpOnly Cookies</Typography>
              </Grid>
              <Grid item xs={12} sm={6}>
                <Typography variant="subtitle2" color="text.secondary">
                  Token Gültigkeit
                </Typography>
                <Typography variant="body1">1 Stunde (automatische Erneuerung)</Typography>
              </Grid>
              <Grid item xs={12} sm={6}>
                <Typography variant="subtitle2" color="text.secondary">
                  Refresh Token
                </Typography>
                <Typography variant="body1">7 Tage</Typography>
              </Grid>
              <Grid item xs={12} sm={6}>
                <Typography variant="subtitle2" color="text.secondary">
                  Rate Limiting
                </Typography>
                <Typography variant="body1">Aktiv (100 Requests/Minute)</Typography>
              </Grid>
            </Grid>
          </Paper>
        </Grid>
      </Grid>
    </Box>
  );
};

export default ProfilePage;