<?php

namespace Randee\Update;

final class UpdateLogger
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $this->normalizeLogFile($logFile ?: $this->getDefaultLogFile());
    }

    public function write(array $payload): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'timestamp' => date('c'),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    private function getDefaultLogFile(): string
    {
        return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/bitrix/tmp/randee.update/update.log';
    }

    private function normalizeLogFile(string $logFile): string
    {
        $logFile = trim($logFile);

        if ($logFile === '') {
            return $this->getDefaultLogFile();
        }

        if ($logFile[0] === '/') {
            return $logFile;
        }

        return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/' . ltrim($logFile, '/');
    }
}
