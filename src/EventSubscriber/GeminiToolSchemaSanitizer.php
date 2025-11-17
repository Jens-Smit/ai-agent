<?php
// src/EventSubscriber/GeminiToolSchemaSanitizer.php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Sanitizes tool schemas before sending to Gemini API
 * Fixes the "cannot start list" error by cleaning up malformed schema properties
 */
class GeminiToolSchemaSanitizer implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger
    ) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Only intercept Gemini API calls
        if (str_contains($url, 'generativelanguage.googleapis.com')) {
            $this->logger->debug('Intercepting Gemini API call', ['url' => $url]);
            
            if (isset($options['json']['tools'])) {
                $this->logger->info('Sanitizing Gemini tool schema');
                $options['json']['tools'] = $this->sanitizeTools($options['json']['tools']);
            }
        }

        return $this->client->request($method, $url, $options);
    }

    private function sanitizeTools(array $tools): array
    {
        foreach ($tools as &$tool) {
            if (isset($tool['function_declarations'])) {
                foreach ($tool['function_declarations'] as &$declaration) {
                    if (isset($declaration['parameters'])) {
                        $declaration['parameters'] = $this->sanitizeParameters($declaration['parameters']);
                    }
                }
            }
        }
        
        return $tools;
    }

    private function sanitizeParameters(array $parameters): array
    {
        // Sanitize properties
        if (isset($parameters['properties'])) {
            foreach ($parameters['properties'] as $propName => &$property) {
                $property = $this->sanitizeProperty($property, $propName);
            }
        }

        // Ensure required is an array of strings
        if (isset($parameters['required'])) {
            if (!is_array($parameters['required'])) {
                $parameters['required'] = [$parameters['required']];
            }
            $parameters['required'] = array_values(array_filter(
                $parameters['required'],
                fn($item) => is_string($item)
            ));
        }

        return $parameters;
    }

    private function sanitizeProperty(array $property, string $propName): array
    {
        // Fix 1: Remove 'value' if it's not a scalar
        if (isset($property['value']) && !is_scalar($property['value'])) {
            $this->logger->warning("Removing non-scalar 'value' from property", [
                'property' => $propName,
                'value_type' => gettype($property['value'])
            ]);
            unset($property['value']);
        }

        // Fix 2: Ensure 'type' is a string, not an array
        if (isset($property['type'])) {
            if (is_array($property['type'])) {
                $this->logger->warning("Converting array 'type' to string", [
                    'property' => $propName,
                    'original_type' => $property['type']
                ]);
                
                // Take first valid type from array
                $validType = $this->extractValidType($property['type']);
                $property['type'] = $validType;
            } elseif (!is_string($property['type'])) {
                $this->logger->warning("Converting non-string 'type' to string", [
                    'property' => $propName,
                    'original_type' => gettype($property['type'])
                ]);
                $property['type'] = 'string'; // fallback
            }
        }

        // Fix 3: Clean nested properties (for object types)
        if (isset($property['properties']) && is_array($property['properties'])) {
            foreach ($property['properties'] as $nestedName => &$nestedProp) {
                $nestedProp = $this->sanitizeProperty($nestedProp, "{$propName}.{$nestedName}");
            }
        }

        // Fix 4: Clean items (for array types)
        if (isset($property['items']) && is_array($property['items'])) {
            $property['items'] = $this->sanitizeProperty($property['items'], "{$propName}[]");
        }

        // Fix 5: Remove any other non-standard fields that Gemini doesn't accept
        $allowedFields = ['type', 'description', 'enum', 'items', 'properties', 'required', 
                          'pattern', 'minimum', 'maximum', 'format', 'default'];
        
        foreach (array_keys($property) as $key) {
            if (!in_array($key, $allowedFields)) {
                $this->logger->debug("Removing non-standard field", [
                    'property' => $propName,
                    'field' => $key
                ]);
                unset($property[$key]);
            }
        }

        return $property;
    }

    private function extractValidType(array $types): string
    {
        // Priority order for type selection
        $typePriority = ['string', 'integer', 'number', 'boolean', 'array', 'object'];
        
        foreach ($typePriority as $preferredType) {
            if (in_array($preferredType, $types)) {
                return $preferredType;
            }
        }

        // If none found, take first element if it's a string
        $first = reset($types);
        return is_string($first) ? $first : 'string';
    }
}