<?php

declare(strict_types=1);

namespace Randee\Update;

final class PackageInstaller
{
    private string $documentRoot;
    private ?string $preferredWorkDir;
    private ?string $workDir = null;
    private PackageValidator $validator;

    public function __construct(?string $documentRoot = null, ?string $workDir = null)
    {
        $this->documentRoot = rtrim((string)($documentRoot ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $this->preferredWorkDir = $this->normalizeConfiguredWorkDir($workDir);
        $this->validator = new PackageValidator();
    }

    public function install(string $packageFile): array
    {
        if (!is_file($packageFile)) {
            return [
                'success' => false,
                'message' => 'Файл пакета не найден',
            ];
        }

        $inspection = $this->validator->inspect($packageFile);
        if (empty($inspection['success'])) {
            return $inspection;
        }

        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'message' => 'Архивный модуль ZipArchive недоступен',
            ];
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($packageFile);
        if ($openResult !== true) {
            return [
                'success' => false,
                'message' => 'Не удалось открыть архив пакета',
            ];
        }

        try {
            $this->workDir = $this->resolveWorkDir();
            if ($this->workDir === null) {
                return [
                    'success' => false,
                    'message' => 'Не удалось подготовить рабочие каталоги',
                    'candidates' => $this->candidateWorkDirs(),
                ];
            }

            $runStamp = date('Ymd_His');
            $extractDir = $this->workDir . '/install-' . $runStamp . '/extract';
            $backupDir = $this->workDir . '/install-' . $runStamp . '/backup';

            if (!$this->ensureDirectory($extractDir) || !$this->ensureDirectory($backupDir)) {
                return [
                    'success' => false,
                    'message' => 'Не удалось подготовить рабочие каталоги',
                ];
            }

            if (!$zip->extractTo($extractDir)) {
                return [
                    'success' => false,
                    'message' => 'Не удалось распаковать архив во временный каталог',
                ];
            }

            $payloadRoot = $extractDir . '/payload';
            if (!is_dir($payloadRoot)) {
                return [
                    'success' => false,
                    'message' => 'В архиве отсутствует каталог payload',
                ];
            }

            $manifest = $inspection['manifest'] ?? [];
            $copyResult = $this->copyTreeToDocumentRoot($payloadRoot, $backupDir);
            if (!$copyResult['success']) {
                return $copyResult;
            }

            $this->cleanupDirectory($extractDir);

            return [
                'success' => true,
                'message' => 'Пакет установлен',
                'backup_dir' => $backupDir,
                'extract_dir' => $extractDir,
                'installed_files' => $copyResult['installed_files'],
                'backed_up_files' => $copyResult['backed_up_files'],
                'backup_map' => $copyResult['backup_map'],
                'manifest' => $manifest,
                'inspection' => $inspection,
            ];
        } finally {
            $zip->close();
        }
    }

    public function getWorkDir(): string
    {
        return (string)($this->workDir ?? $this->preferredWorkDir ?? '');
    }

    private function resolveWorkDir(): ?string
    {
        foreach ($this->candidateWorkDirs() as $candidate) {
            if ($this->ensureDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function candidateWorkDirs(): array
    {
        $candidates = [];

        if ($this->preferredWorkDir !== null && $this->preferredWorkDir !== '') {
            $candidates[] = $this->preferredWorkDir;
        }

        if ($this->documentRoot !== '') {
            $candidates[] = $this->documentRoot . '/bitrix/tmp/randee.update';
            $candidates[] = $this->documentRoot . '/upload/randee.update';
        }

        $systemTemp = rtrim((string)sys_get_temp_dir(), '/');
        if ($systemTemp !== '') {
            $candidates[] = $systemTemp . '/randee.update';
        }

        $candidates[] = '/tmp/randee.update';
        $candidates[] = '/var/tmp/randee.update';

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                $unique[$candidate] = true;
            }
        }

        return array_keys($unique);
    }

    private function normalizeConfiguredWorkDir(?string $workDir): ?string
    {
        $workDir = trim((string)$workDir);

        if ($workDir === '') {
            return null;
        }

        if ($workDir[0] === '/') {
            return $workDir;
        }

        if ($this->documentRoot !== '') {
            return $this->documentRoot . '/' . ltrim($workDir, '/');
        }

        return rtrim((string)sys_get_temp_dir(), '/') . '/' . ltrim($workDir, '/');
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return @mkdir($path, 0775, true) && is_writable($path);
    }

    private function ensurePathForInstall(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return @mkdir($path, 0775, true) || is_dir($path);
    }

    private function copyTreeToDocumentRoot(string $sourceRoot, string $backupDir): array
    {
        $installedFiles = [];
        $backedUpFiles = [];
        $backupMap = [];
        $rootLength = strlen(rtrim($sourceRoot, '/') . '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = substr($sourcePath, $rootLength);
            $targetPath = $this->documentRoot . '/' . $relativePath;

            if ($item->isDir()) {
                continue;
            }

            if (!is_dir(dirname($targetPath)) && !mkdir(dirname($targetPath), 0775, true) && !is_dir(dirname($targetPath))) {
                return [
                    'success' => false,
                    'message' => 'Не удалось создать каталог для файла: ' . $relativePath,
                ];
            }

            if (is_file($targetPath)) {
                $backupTarget = $backupDir . '/' . $relativePath;
                if (!is_dir(dirname($backupTarget)) && !mkdir(dirname($backupTarget), 0775, true) && !is_dir(dirname($backupTarget))) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось подготовить backup для файла: ' . $relativePath,
                    ];
                }

                if (!copy($targetPath, $backupTarget)) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось сохранить backup файла: ' . $relativePath,
                    ];
                }

                $backedUpFiles[] = $backupTarget;
                $backupMap[$targetPath] = $backupTarget;
            }

            if (!copy($sourcePath, $targetPath)) {
                return [
                    'success' => false,
                    'message' => 'Не удалось установить файл: ' . $relativePath,
                ];
            }

            $installedFiles[] = $targetPath;
        }

        return [
            'success' => true,
            'installed_files' => $installedFiles,
            'backed_up_files' => $backedUpFiles,
            'backup_map' => $backupMap,
        ];
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
        @rmdir(dirname($path));
    }
}
