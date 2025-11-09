<?php

namespace App\Service;

class AgentStatusService
{
    private array $statusMessages = [];

    public function addStatus(string $message): void
    {
        $this->statusMessages[] = [
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'message' => $message,
        ];
    }

    public function getStatuses(): array
    {
        return $this->statusMessages;
    }

    public function clearStatuses(): void
    {
        $this->statusMessages = [];
    }
}
