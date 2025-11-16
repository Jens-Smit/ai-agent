<?php

namespace App\Service;

class GeminiToolSanitizer {

    public function sanitize(array $tools): array
    {
        foreach ($tools as &$tool) {
            if (!isset($tool['function_declarations'])) {
                continue;
            }

            foreach ($tool['function_declarations'] as &$decl) {
                if (isset($decl['parameters'])) {
                    $decl['parameters'] = $this->fixNode($decl['parameters']);
                }
            }
        }

        return $tools;
    }

    private function fixNode(array $node): array
    {
        // Fix illegal 'type' structures
        if (isset($node['type']) && !is_string($node['type'])) {
            if (is_array($node['type']) && isset($node['type'][0]) && is_string($node['type'][0])) {
                $node['type'] = $node['type'][0]; // take first
            } else {
                $node['type'] = 'string'; // fallback
            }
        }

        // Remove non-scalar 'value'
        if (isset($node['value']) && !is_scalar($node['value'])) {
            unset($node['value']);
        }

        // Dive deeper
        foreach ($node as $key => &$child) {
            if (is_array($child)) {
                $child = $this->fixNode($child);
            }
        }

        return $node;
    }
}
