<?php

namespace Randee\Update;

final class RollbackManager
{
    private string $documentRoot;

    public function __construct(?string $documentRoot = null)
    {
        $this->documentRoot = rtrim((string)($documentRoot ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    }

    public function rollback(array $installState): array
    {
        $backupMap = $installState['backup_map'] ?? [];
        $installedFiles = $installState['installed_files'] ?? [];

        if (!is_array($backupMap) || !is_array($installedFiles)) {
            return [
                'success' => false,
                'message' => 'Некорректное состояние для отката',
            ];
        }

        $restoredFiles = [];
        $removedFiles = [];

        foreach ($installedFiles as $targetPath) {
            $targetPath = (string)$targetPath;
            if ($targetPath === '') {
                continue;
            }

            $backupPath = isset($backupMap[$targetPath]) ? (string)$backupMap[$targetPath] : '';
            if ($backupPath !== '' && is_file($backupPath)) {
                $this->ensureDirectory(dirname($targetPath));
                if (!copy($backupPath, $targetPath)) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось восстановить файл: ' . $targetPath,
                    ];
                }

                $restoredFiles[] = $targetPath;
                continue;
            }

            if (is_file($targetPath) && !unlink($targetPath)) {
                return [
                    'success' => false,
                    'message' => 'Не удалось удалить новый файл: ' . $targetPath,
                ];
            }

            $removedFiles[] = $targetPath;
        }

        $this->cleanupEmptyDirs(array_values(array_unique(array_map('dirname', $installedFiles))));

        return [
            'success' => true,
            'message' => 'Откат выполнен',
            'restored_files' => $restoredFiles,
            'removed_files' => $removedFiles,
        ];
    }

    private function ensureDirectory(string $path): bool
    {
        if ($path === '' || $path === '.' || $path === DIRECTORY_SEPARATOR) {
            return true;
        }

        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0775, true) || is_dir($path);
    }

    private function cleanupEmptyDirs(array $directories): void
    {
        usort($directories, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        foreach ($directories as $directory) {
            $directory = (string)$directory;
            if ($directory === '' || $directory === '.' || $directory === $this->documentRoot) {
                continue;
            }

            $current = $directory;
            while ($current !== '' && str_starts_with($current, $this->documentRoot)) {
                if (!is_dir($current)) {
                    $current = dirname($current);
                    continue;
                }

                $entries = array_values(array_diff(scandir($current) ?: [], ['.', '..']));
                if (!empty($entries)) {
                    break;
                }

                @rmdir($current);
                $current = dirname($current);
            }
        }
    }
}
