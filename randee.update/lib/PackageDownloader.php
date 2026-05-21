<?php

declare(strict_types=1);

namespace Randee\Update;

final class PackageDownloader
{
    private ?string $preferredTempDir;
    private ?string $tempDir = null;

    public function __construct(?string $tempDir = null)
    {
        $this->preferredTempDir = $this->normalizeConfiguredTempDir($tempDir);
    }

    public function download(string $packageUrl, ?string $expectedSha256 = null, array $headers = [], string $method = 'GET', ?string $body = null): array
    {
        if (!$this->isValidUrl($packageUrl)) {
            return [
                'success' => false,
                'message' => 'Некорректный URL пакета',
            ];
        }

        $this->tempDir = $this->resolveTempDir();
        if ($this->tempDir === null) {
            return [
                'success' => false,
                'message' => 'Не удалось подготовить временный каталог',
                'candidates' => $this->candidateTempDirs(),
            ];
        }

        $targetFile = $this->buildTargetPath($packageUrl);
        $raw = false;
        foreach ($this->urlCandidates($packageUrl) as $candidateUrl) {
            $raw = $this->fetchRemoteFile($candidateUrl, $headers, $method, $body);
            if ($raw !== false && $raw !== '') {
                break;
            }
        }

        if ($raw === false || $raw === '') {
            return [
                'success' => false,
                'message' => 'Не удалось скачать пакет',
            ];
        }

        if (file_put_contents($targetFile, $raw) === false) {
            return [
                'success' => false,
                'message' => 'Не удалось сохранить пакет во временный каталог',
            ];
        }

        $actualSha256 = hash_file('sha256', $targetFile) ?: '';

        if ($expectedSha256 !== null && $expectedSha256 !== '' && !$this->matchesSha256($expectedSha256, $actualSha256)) {
            @unlink($targetFile);

            return [
                'success' => false,
                'message' => 'SHA256 пакета не совпал',
                'actual_sha256' => $actualSha256,
                'expected_sha256' => $expectedSha256,
            ];
        }

        $archiveCheck = $this->validateArchive($targetFile);
        if ($archiveCheck !== true) {
            @unlink($targetFile);

            return [
                'success' => false,
                'message' => $archiveCheck,
                'actual_sha256' => $actualSha256,
            ];
        }

        return [
            'success' => true,
            'message' => 'Пакет скачан',
            'file' => $targetFile,
            'size' => filesize($targetFile) ?: 0,
            'sha256' => $actualSha256,
            'verified' => $expectedSha256 !== null && $expectedSha256 !== '',
        ];
    }

    public function getTempDir(): string
    {
        return (string)($this->tempDir ?? $this->preferredTempDir ?? '');
    }

    private function resolveTempDir(): ?string
    {
        foreach ($this->candidateTempDirs() as $candidate) {
            if ($this->ensureDirectory($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function candidateTempDirs(): array
    {
        $candidates = [];

        if ($this->preferredTempDir !== null && $this->preferredTempDir !== '') {
            $candidates[] = $this->preferredTempDir;
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot !== '') {
            $candidates[] = $docRoot . '/bitrix/tmp/randee.update';
            $candidates[] = $docRoot . '/upload/randee.update';
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

    private function normalizeConfiguredTempDir(?string $tempDir): ?string
    {
        $tempDir = trim((string)$tempDir);
        if ($tempDir === '') {
            return null;
        }

        if ($tempDir[0] === '/') {
            return $tempDir;
        }

        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot !== '') {
            return $docRoot . '/' . ltrim($tempDir, '/');
        }

        return rtrim((string)sys_get_temp_dir(), '/') . '/' . ltrim($tempDir, '/');
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return @mkdir($path, 0775, true) && is_writable($path);
    }

    private function buildTargetPath(string $packageUrl): string
    {
        $path = parse_url($packageUrl, PHP_URL_PATH) ?: '';
        $baseName = basename($path);

        if ($baseName === '' || $baseName === '.' || $baseName === '/') {
            $baseName = 'release-package.zip';
        }

        $stamp = date('Ymd_His');

        return rtrim($this->tempDir, '/') . '/' . $stamp . '_' . $baseName;
    }

    private function fetchRemoteFile(string $packageUrl, array $headers = [], string $method = 'GET', ?string $body = null): string|false
    {
        $headerLines = [];
        foreach ($headers as $header) {
            $header = trim((string)$header);
            if ($header !== '') {
                $headerLines[] = $header;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => 60,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
            ],
            'https' => [
                'method' => $method,
                'timeout' => 60,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        return @file_get_contents($packageUrl, false, $context);
    }

    /**
     * @return array<int, string>
     */
    private function urlCandidates(string $packageUrl): array
    {
        $candidates = [$packageUrl];
        $host = (string)parse_url($packageUrl, PHP_URL_HOST);

        if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $path = parse_url($packageUrl, PHP_URL_PATH) ?: '';
            $query = parse_url($packageUrl, PHP_URL_QUERY);
            $fallback = 'https://127.0.0.1' . $path;
            if (is_string($query) && $query !== '') {
                $fallback .= '?' . $query;
            }
            $candidates[] = $fallback;
        }

        return array_values(array_unique($candidates));
    }

    private function isValidUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://~i', trim($url));
    }

    private function matchesSha256(string $expectedSha256, string $actualSha256): bool
    {
        return strtolower(trim($expectedSha256)) === strtolower(trim($actualSha256));
    }

    private function validateArchive(string $filePath): bool|string
    {
        if (!class_exists('ZipArchive')) {
            return 'Архивный модуль ZipArchive недоступен';
        }

        $zip = new \ZipArchive();
        $result = $zip->open($filePath);

        if ($result === true) {
            $zip->close();
            return true;
        }

        return 'Файл не прошёл проверку как ZIP-архив';
    }
}
