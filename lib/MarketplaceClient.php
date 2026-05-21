<?php

declare(strict_types=1);

namespace Randee\Update;

final class MarketplaceClient
{
    private string $baseUrl;
    private string $baseHost;
    private ?string $licenseKey;
    private ?string $siteUid;
    private ?string $channel;
    private ?string $tempDir;

    public function __construct(string $baseUrl, ?string $licenseKey = null, ?string $siteUid = null, ?string $channel = null, ?string $tempDir = null)
    {
        $baseUrl = trim($baseUrl);
        $this->baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://updates.c0l.ru';
        $this->baseHost = (string)(parse_url($this->baseUrl, PHP_URL_HOST) ?: 'updates.c0l.ru');
        $this->licenseKey = $licenseKey !== null ? trim($licenseKey) : null;
        $this->siteUid = $siteUid !== null ? trim($siteUid) : null;
        $this->channel = $channel !== null ? trim($channel) : null;
        $this->tempDir = $tempDir !== null ? trim($tempDir) : null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function activateLicense(array $payload): array
    {
        $payload['license_key'] = trim((string)($payload['license_key'] ?? $this->licenseKey ?? ''));
        $payload['site_uid'] = trim((string)($payload['site_uid'] ?? $this->siteUid ?? ''));
        $payload['channel'] = trim((string)($payload['channel'] ?? $this->channel ?? 'stable'));

        return $this->requestJson('POST', '/api/v1/license/activate', $payload, true);
    }

    public function catalog(): array
    {
        return $this->requestJson('GET', '/api/v1/catalog', null, true);
    }

    public function health(): array
    {
        return $this->requestJson('GET', '/api/v1/health', null, false);
    }

    public function product(string $productId): array
    {
        return $this->requestJson('GET', '/api/v1/products/' . rawurlencode($productId), null, true);
    }

    public function latestRelease(string $productId): array
    {
        return $this->requestJson('GET', '/api/v1/products/' . rawurlencode($productId) . '/releases/latest', null, true);
    }

    public function downloadPackage(string $productId, ?string $expectedSha256 = null, ?int $releaseId = null): array
    {
        if ($releaseId === null) {
            $latestRelease = $this->latestRelease($productId);
            $releaseId = $this->extractReleaseId($latestRelease);
        }

        $body = $releaseId !== null ? ['release_id' => $releaseId] : [];
        $headers = $this->buildHeaders(true);
        $payload = $body !== [] ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $downloader = new PackageDownloader($this->tempDir);

        foreach ($this->requestUrlCandidates('/api/v1/products/' . rawurlencode($productId) . '/download') as $url) {
            $result = $downloader->download($url, $expectedSha256, $headers, 'POST', $payload);
            if (($result['success'] ?? false) === true) {
                return $result;
            }

            if (!empty($result['message']) && $result['message'] !== 'Не удалось скачать пакет') {
                return $result;
            }
        }

        return [
            'success' => false,
            'message' => $releaseId === null
                ? 'Не удалось определить релиз для скачивания'
                : 'Не удалось скачать пакет',
        ];
    }

    public function getSiteUid(): string
    {
        return (string)($this->siteUid ?? '');
    }

    public function getChannel(): string
    {
        return (string)($this->channel ?? 'stable');
    }

    private function extractReleaseId(array $response): ?int
    {
        $candidates = [
            $response['data']['release']['id'] ?? null,
            $response['data']['id'] ?? null,
            $response['release']['id'] ?? null,
            $response['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_numeric($candidate)) {
                return (int)$candidate;
            }
        }

        return null;
    }


    private function requestJson(string $method, string $path, ?array $payload = null, bool $sendAuth = false): array
    {
        $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $headers = $this->buildHeaders($sendAuth);
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        foreach ($this->requestUrlCandidates($path) as $url) {
            $response = $this->requestRaw($url, $method, $headers, $body);
            $raw = $response['raw'];
            $statusCode = $response['status_code'];

            if ($raw === false) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [
                    'success' => $statusCode >= 200 && $statusCode < 300,
                    'status_code' => $statusCode,
                    'message' => 'Ответ marketplace не является JSON',
                    'raw' => $raw,
                    'url' => $url,
                ];
            }

            return [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'data' => $decoded,
                'url' => $url,
            ];
        }

        return [
            'success' => false,
            'status_code' => 0,
            'message' => 'Не удалось связаться с marketplace',
            'url' => $this->buildUrl($path),
        ];
    }

    private function buildUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array<int,string>
     */
    private function buildHeaders(bool $sendAuth): array
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: RandeeUpdate/0.3',
        ];

        if ($sendAuth && $this->licenseKey !== null && $this->licenseKey !== '') {
            $headers[] = 'X-License-Key: ' . $this->licenseKey;
            $headers[] = 'Authorization: Bearer ' . $this->licenseKey;
        }

        if ($this->siteUid !== null && $this->siteUid !== '') {
            $headers[] = 'X-Site-Uid: ' . $this->siteUid;
        }

        if ($this->channel !== null && $this->channel !== '') {
            $headers[] = 'X-Channel: ' . $this->channel;
        }

        if ($this->baseUrl !== '') {
            $headers[] = 'X-Domain: ' . (string)($_SERVER['HTTP_HOST'] ?? '');
        }

        if ($this->baseHost !== '') {
            $headers[] = 'Host: ' . $this->baseHost;
        }

        return $headers;
    }

    /**
     * @return array<int, string>
     */
    private function requestUrlCandidates(string $path): array
    {
        $primary = $this->buildUrl($path);
        $candidates = [$primary];
        $host = (string)parse_url($primary, PHP_URL_HOST);

        if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $candidates[] = 'https://127.0.0.1/' . ltrim($path, '/');
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array{raw:string|false,status_code:int}
     */
    private function requestRaw(string $url, string $method, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        return [
            'raw' => $raw,
            'status_code' => $this->extractStatusCode($http_response_header ?? []),
        ];
    }

    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string)$header, $m)) {
                return (int)$m[1];
            }
        }

        return 0;
    }
}
