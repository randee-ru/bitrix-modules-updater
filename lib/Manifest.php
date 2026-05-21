<?php

namespace Randee\Update;

final class Manifest
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $this->normalizePath($path ?: $this->getDefaultPath());
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        if ($this->isUrlSource()) {
            return $this->read() !== [];
        }

        return is_file($this->path);
    }

    public function read(): array
    {
        if ($this->path === '') {
            return [];
        }

        $raw = $this->readRaw();
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function getVersion(): string
    {
        $data = $this->read();
        return (string)($data['version'] ?? '');
    }

    public function getCommit(): string
    {
        $data = $this->read();
        return (string)($data['commit'] ?? '');
    }

    public function getReleasedAt(): string
    {
        $data = $this->read();
        return (string)($data['released_at'] ?? '');
    }

    public function getChangelog(): array
    {
        $data = $this->read();
        $changelog = $data['changelog'] ?? [];

        return is_array($changelog) ? $changelog : [];
    }

    public function getPackageUrl(): string
    {
        $data = $this->read();
        return (string)($data['package_url'] ?? '');
    }

    public function getReleaseTag(): string
    {
        $data = $this->read();
        return (string)($data['release_tag'] ?? '');
    }

    public function getBuildNumber(): string
    {
        $data = $this->read();
        return (string)($data['build_number'] ?? '');
    }

    public function getChannel(): string
    {
        $data = $this->read();
        return (string)($data['channel'] ?? '');
    }

    public function getSha256(): string
    {
        $data = $this->read();
        return (string)($data['sha256'] ?? '');
    }

    private function getDefaultPath(): string
    {
        return '/local/update/manifest.json';
    }

    private function readRaw(): string|false
    {
        return @file_get_contents($this->path);
    }

    private function isUrlSource(): bool
    {
        return (bool) preg_match('~^https?://~i', $this->path);
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/local/update/manifest.json';
        }

        if ($this->isUrlString($path)) {
            return $path;
        }

        if ($path[0] === '/') {
            return rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . $path;
        }

        return $path;
    }

    private function isUrlString(string $path): bool
    {
        return (bool) preg_match('~^https?://~i', $path);
    }
}
