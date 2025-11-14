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
  Divider,
  Card,
  CardContent,
} from '@mui/material';
import { Send, SmartToy, Person, Code } from '@mui/icons-material';
import { callPersonalAssistant, callDevAgent, callFrontendDevAgent } from '../api/agent';
import { getErrorMessage } from '../api/client';

/**
 * Agent Configuration
 */
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
        {message.metadata && (
          <Box sx={{ mt: 2, pt: 2, borderTop: '1px solid rgba(255,255,255,0.1)' }}>
            <Typography variant="caption" display="block">
              Token Usage: {message.metadata.token_usage || 'N/A'}
            </Typography>
            {message.metadata.files_created && (
              <Typography variant="caption" display="block">
                Files: {message.metadata.files_created.join(', ')}
              </Typography>
            )}
          </Box>
        )}
      </CardContent>
    </Card>
  </Box>
);

/**
 * Agent Page Component
 */
const AgentPage = ({ agentType = 'personal' }) => {
  const config = AGENT_CONFIG[agentType];
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
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
    setInput('');
    setError(null);
    setLoading(true);

    try {
      const response = await config.apiCall(input);

      const agentMessage = {
        text: response.response || response.ai_response || 'Keine Antwort erhalten',
        isUser: false,
        timestamp: new Date(),
        metadata: {
          token_usage: response.metadata?.token_usage,
          files_created: response.files_created,
          deployment_instructions: response.deployment_instructions,
        },
      };

      setMessages((prev) => [...prev, agentMessage]);

      // Show deployment instructions if available
      if (response.deployment_instructions) {
        const deployMessage = {
          text: `📦 Deployment Instructions:\n\n${response.deployment_instructions}`,
          isUser: false,
          timestamp: new Date(),
        };
        setMessages((prev) => [...prev, deployMessage]);
      }
    } catch (err) {
      setError(getErrorMessage(err));
      const errorMessage = {
        text: `Fehler: ${getErrorMessage(err)}`,
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

      {/* Chat Messages */}
      <Paper
        sx={{
          height: 'calc(100vh - 400px)',
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
                  Agent antwortet...
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
            ? '⚠️ Code-Generierung kann mehrere Minuten dauern'
            : 'Drücke Enter zum Senden, Shift+Enter für neue Zeile'}
        </Typography>
      </Paper>
    </Box>
  );
};

export default AgentPage;