<?php
// debug_tool_schema.php - Im Projektroot ausführen

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// .env laden
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

// Kernel booten
$kernel = new App\Kernel($_ENV['APP_ENV'], (bool) $_ENV['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();

// Agent holen
$agent = $container->get('ai.agent.personal_assistent');

// Reflection nutzen um Toolbox zu extrahieren
$reflection = new ReflectionClass($agent);
$property = $reflection->getProperty('toolbox');
$property->setAccessible(true);
$toolbox = $property->getValue($agent);

// Tools ausgeben
echo "=== REGISTERED TOOLS ===\n\n";
$tools = $toolbox->getTools();

foreach ($tools as $index => $tool) {
    echo "Tool [{$index}]: {$tool->getName()}\n";
    echo "Description: {$tool->getDescription()}\n";
    echo "Parameters Schema:\n";
    print_r($tool->getParameters());
    echo "\n" . str_repeat('-', 80) . "\n\n";
}