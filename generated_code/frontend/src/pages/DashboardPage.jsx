import React from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Box,
  Grid,
  Card,
  CardContent,
  CardActions,
  Typography,
  Button,
  Paper,
} from '@mui/material';
import {
  SmartToy,
  Code,
  Web,
  TrendingUp,
  Speed,
  Security,
} from '@mui/icons-material';
import { useAuth } from '../context/AuthContext';

/**
 * Dashboard Page Component
 */
const DashboardPage = () => {
  const navigate = useNavigate();
  const { user } = useAuth();

  const agents = [
    {
      title: 'Personal Assistant',
      description: 'Dein persönlicher AI Assistent für alltägliche Aufgaben und Fragen',
      icon: <SmartToy sx={{ fontSize: 60 }} />,
      color: 'primary.main',
      path: '/agent',
      features: ['Konversational', 'Gedächtnis', 'Web-Suche'],
    },
    {
      title: 'Development Agent',
      description: 'Generiert Symfony/PHP Code mit Tests und Deployment-Scripts',
      icon: <Code sx={{ fontSize: 60 }} />,
      color: 'secondary.main',
      path: '/dev-agent',
      features: ['Code-Generation', 'Tests', 'Deployment'],
    },
    {
      title: 'Frontend Generator',
      description: 'Erstellt React und React Native Frontend-Anwendungen',
      icon: <Web sx={{ fontSize: 60 }} />,
      color: 'success.main',
      path: '/frontend-agent',
      features: ['React 19', 'Material UI', 'Mobile'],
    },
  ];

  const stats = [
    { label: 'Verfügbare Agents', value: '3', icon: <SmartToy /> },
    { label: 'API Status', value: 'Online', icon: <TrendingUp />, color: 'success' },
    { label: 'Durchschnittliche Antwortzeit', value: '2.3s', icon: <Speed /> },
    { label: 'Sicherheit', value: 'JWT + httpOnly', icon: <Security /> },
  ];

  return (
    <Box>
      {/* Welcome Header */}
      <Paper sx={{ p: 3, mb: 4, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', color: 'white' }}>
        <Typography variant="h3" gutterBottom>
          Willkommen zurück! 👋
        </Typography>
        <Typography variant="body1">
          {user?.email || 'Nutzer'}
        </Typography>
        <Typography variant="body2" sx={{ mt: 1, opacity: 0.9 }}>
          Wähle einen AI Agent aus oder erkunde deine Optionen
        </Typography>
      </Paper>

      {/* Stats */}
      <Grid container spacing={3} sx={{ mb: 4 }}>
        {stats.map((stat, index) => (
          <Grid item xs={12} sm={6} md={3} key={index}>
            <Card>
              <CardContent sx={{ textAlign: 'center' }}>
                <Box sx={{ color: stat.color ? `${stat.color}.main` : 'primary.main', mb: 1 }}>
                  {stat.icon}
                </Box>
                <Typography variant="h4" gutterBottom>
                  {stat.value}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {stat.label}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Agent Cards */}
      <Typography variant="h5" gutterBottom sx={{ mb: 3 }}>
        Verfügbare AI Agents
      </Typography>
      <Grid container spacing={3}>
        {agents.map((agent, index) => (
          <Grid item xs={12} md={4} key={index}>
            <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
              <CardContent sx={{ flexGrow: 1 }}>
                <Box
                  sx={{
                    color: agent.color,
                    mb: 2,
                    textAlign: 'center',
                  }}
                >
                  {agent.icon}
                </Box>
                <Typography variant="h5" gutterBottom textAlign="center">
                  {agent.title}
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  {agent.description}
                </Typography>
                <Box sx={{ mt: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>
                    Features:
                  </Typography>
                  {agent.features.map((feature, i) => (
                    <Typography
                      key={i}
                      variant="body2"
                      color="text.secondary"
                      sx={{ display: 'flex', alignItems: 'center', mb: 0.5 }}
                    >
                      • {feature}
                    </Typography>
                  ))}
                </Box>
              </CardContent>
              <CardActions>
                <Button
                  fullWidth
                  variant="contained"
                  onClick={() => navigate(agent.path)}
                >
                  Starten
                </Button>
              </CardActions>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Quick Links */}
      <Paper sx={{ p: 3, mt: 4 }}>
        <Typography variant="h6" gutterBottom>
          Schnellzugriff
        </Typography>
        <Grid container spacing={2}>
          <Grid item xs={12} sm={6} md={3}>
            <Button
              fullWidth
              variant="outlined"
              onClick={() => navigate('/profile')}
            >
              Profil bearbeiten
            </Button>
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <Button
              fullWidth
              variant="outlined"
              onClick={() => window.open(`${process.env.REACT_APP_API_URL?.replace('/api', '')}/api/doc`, '_blank')}
            >
              API Dokumentation
            </Button>
          </Grid>
        </Grid>
      </Paper>
    </Box>
  );
};

export default DashboardPage;