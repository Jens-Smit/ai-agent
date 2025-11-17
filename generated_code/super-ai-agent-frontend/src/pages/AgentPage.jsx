import React, { useState, useRef, useEffect } from 'react';
import {
  Box,
  Paper,
  TextField,
  Button,
  Typography,
  CircularProgress,
  Alert,
  Chip,
  Card,
  CardContent,
  LinearProgress,
  List,
  ListItem,
  ListItemText,
  IconButton,
  Collapse
} from '@mui/material';
import { 
  Send, 
  SmartToy, 
  Person, 
  Code,
  ExpandMore,
  ExpandLess,
  CheckCircle,
  Error as ErrorIcon
} from '@mui/icons-material';
import { 
  callPersonalAssistant, 
  callDevAgent, 
  callFrontendDevAgent 
} from '../api/agent';
import { useAgentStatus } from '../hooks/useAgentStatus';
import { getErrorMessage } from '../api/client';

const AGENT_CONFIG = {
  personal: {
    title: 'Personal Assistant',
    description: 'Dein persönlicher AI Assistent für allgemeine Aufgaben',
    icon: <SmartToy />,
    apiCall: callPersonalAssistant,
    color: 'primary',
  },
  dev: {
    title: 'Development Agent',
    description: 'Symfony/PHP Code-Generator mit Tests und Deployment',
    icon: <Code />,
    apiCall: callDevAgent,
    color: 'secondary',
  },
  frontend: {
    title: 'Frontend Generator',
    description: 'React/React Native Frontend-Generator',
    icon: <Code />,
    apiCall: callFrontendDevAgent,
    color: 'success',
  },
};

/**
 * Status Monitor Component
 */
const StatusMonitor = ({ sessionId }) => {
  const { statuses, completed, result, error, isPolling } = useAgentStatus(sessionId);
  const [expanded, setExpanded] = useState(true);

  if (!sessionId) return null;

  return (
    <Card sx={{ mb: 2, bgcolor: 'grey.50' }}>
      <CardContent>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <Typography variant="h6">
            Agent Status Monitor
          </Typography>
          <IconButton onClick={() => setExpanded(!expanded)} size="small">
            {expanded ? <ExpandLess /> : <ExpandMore />}
          </IconButton>
        </Box>
        
        <Typography variant="caption" color="text.secondary" display="block">
          Session: {sessionId}
        </Typography>
        
        {isPolling && !completed && (
          <LinearProgress sx={{ mt: 2, mb: 1 }} />
        )}

        <Collapse in={expanded}>
          <List sx={{ maxHeight: 300, overflow: 'auto', mt: 2 }}>
            {statuses.map((status, index) => (
              <ListItem 
                key={index}
                sx={{ 
                  py: 0.5,
                  borderLeft: '3px solid',
                  borderColor: 'primary.main',
                  mb: 0.5,
                  bgcolor: 'background.paper'
                }}
              >
                <ListItemText
                  primary={status.message}
                  secondary={new Date(status.timestamp).toLocaleString('de-DE')}
                  primaryTypographyProps={{ variant: 'body2' }}
                  secondaryTypographyProps={{ variant: 'caption' }}
                />
              </ListItem>
            ))}
          </List>
        </Collapse>

        {completed && (
          <Box sx={{ mt: 2 }}>
            {error ? (
              <Alert severity="error" icon={<ErrorIcon />}>
                {error}
              </Alert>
            ) : (
              <Alert severity="success" icon={<CheckCircle />}>
                Agent completed successfully
              </Alert>
            )}
          </Box>
        )}
      </CardContent>
    </Card>
  );
};

/**
 * Message Component
 */
const Message = ({ message, isUser }) => (
  <Box
    sx={{
      display: 'flex',
      justifyContent: isUser ? 'flex-end' : 'flex-start',
      mb: 2,
    }}
  >
    <Card
      sx={{
        maxWidth: '70%',
        bgcolor: isUser ? 'primary.main' : 'grey.100',
        color: isUser ? 'white' : 'text.primary',
      }}
    >
      <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
        <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
          {isUser ? <Person sx={{ mr: 1 }} /> : <SmartToy sx={{ mr: 1 }} />}
          <Typography variant="subtitle2">
            {isUser ? 'Du' : 'AI Agent'}
          </Typography>
        </Box>
        <Typography variant="body1" sx={{ whiteSpace: 'pre-wrap' }}>
          {message.text}
        </Typography>
        {message.sessionId && (
          <Typography variant="caption" display="block" sx={{ mt: 1, opacity: 0.7 }}>
            Session: {message.sessionId}
          </Typography>
        )}
      </CardContent>
    </Card>
  </Box>
);

/**
 * Main Agent Page Component
 */
const AgentPage = ({ agentType = 'personal' }) => {
  const config = AGENT_CONFIG[agentType];
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [currentSessionId, setCurrentSessionId] = useState(null);
  const messagesEndRef = useRef(null);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  const handleSend = async () => {
    if (!input.trim() || loading) return;

    const userMessage = {
      text: input,
      isUser: true,
      timestamp: new Date(),
    };

    setMessages((prev) => [...prev, userMessage]);
    const currentInput = input;
    setInput('');
    setError(null);
    setLoading(true);
    setCurrentSessionId(null);

    try {
      const response = await config.apiCall(currentInput);

      // Backend gibt jetzt sessionId zurück
      if (response.sessionId) {
        setCurrentSessionId(response.sessionId);
        
        const agentMessage = {
          text: response.message || 'Agent job gestartet. Status-Updates werden angezeigt.',
          isUser: false,
          timestamp: new Date(),
          sessionId: response.sessionId,
        };
        
        setMessages((prev) => [...prev, agentMessage]);
      } else {
        // Fallback für alte Response-Format
        const agentMessage = {
          text: response.response || response.ai_response || 'Keine Antwort erhalten',
          isUser: false,
          timestamp: new Date(),
        };
        setMessages((prev) => [...prev, agentMessage]);
      }
    } catch (err) {
      const errorMsg = getErrorMessage(err);
      setError(errorMsg);
      
      const errorMessage = {
        text: `Fehler: ${errorMsg}`,
        isUser: false,
        timestamp: new Date(),
      };
      setMessages((prev) => [...prev, errorMessage]);
    } finally {
      setLoading(false);
    }
  };

  const handleKeyPress = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSend();
    }
  };

  return (
    <Box>
      {/* Header */}
      <Paper sx={{ p: 3, mb: 3 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
          {config.icon}
          <Typography variant="h4" sx={{ ml: 2 }}>
            {config.title}
          </Typography>
        </Box>
        <Typography variant="body2" color="text.secondary">
          {config.description}
        </Typography>
        <Box sx={{ mt: 2 }}>
          <Chip
            label={agentType === 'personal' ? 'Conversational' : 'Code Generation'}
            color={config.color}
            size="small"
            sx={{ mr: 1 }}
          />
          <Chip label="Status: Online" color="success" size="small" />
        </Box>
      </Paper>

      {error && (
        <Alert severity="error" sx={{ mb: 3 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}

      {/* Status Monitor */}
      {currentSessionId && <StatusMonitor sessionId={currentSessionId} />}

      {/* Chat Messages */}
      <Paper
        sx={{
          height: 'calc(100vh - 500px)',
          minHeight: '400px',
          overflowY: 'auto',
          p: 3,
          mb: 2,
        }}
      >
        {messages.length === 0 ? (
          <Box
            sx={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              height: '100%',
              flexDirection: 'column',
            }}
          >
            {config.icon}
            <Typography variant="h6" color="text.secondary" sx={{ mt: 2 }}>
              Starte eine Konversation
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {agentType === 'personal'
                ? 'Stelle eine Frage oder bitte um Hilfe'
                : 'Beschreibe was du entwickeln möchtest'}
            </Typography>
          </Box>
        ) : (
          <Box>
            {messages.map((message, index) => (
              <Message key={index} message={message} isUser={message.isUser} />
            ))}
            {loading && (
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
                <CircularProgress size={24} sx={{ mr: 2 }} />
                <Typography variant="body2" color="text.secondary">
                  Agent wird gestartet...
                </Typography>
              </Box>
            )}
            <div ref={messagesEndRef} />
          </Box>
        )}
      </Paper>

      {/* Input Area */}
      <Paper sx={{ p: 2 }}>
        <Box sx={{ display: 'flex', alignItems: 'flex-end', gap: 2 }}>
          <TextField
            fullWidth
            multiline
            maxRows={4}
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyPress={handleKeyPress}
            placeholder={
              agentType === 'personal'
                ? 'Schreibe eine Nachricht...'
                : 'Beschreibe deine Code-Anforderungen...'
            }
            disabled={loading}
          />
          <Button
            variant="contained"
            endIcon={<Send />}
            onClick={handleSend}
            disabled={loading || !input.trim()}
            sx={{ minWidth: '120px' }}
          >
            Senden
          </Button>
        </Box>
        <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: 'block' }}>
          {agentType === 'dev' || agentType === 'frontend'
            ? '⚠️ Code-Generierung läuft asynchron - Status-Updates werden live angezeigt'
            : 'Drücke Enter zum Senden, Shift+Enter für neue Zeile'}
        </Typography>
      </Paper>
    </Box>
  );
};

export default AgentPage;