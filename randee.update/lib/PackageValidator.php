<?php

declare(strict_types=1);

namespace Randee\Update;

final class PackageValidator
{
    private const ALLOWED_TYPES = ['module', 'component', 'template', 'solution', 'package'];
    private const ALLOWED_CHANNELS = ['stable', 'beta', 'hotfix', 'dev'];

    public function inspect(string $packageFile): array
    {
        if (!is_file($packageFile)) {
            return $this->fail('Файл пакета не найден');
        }

        if (!class_exists('ZipArchive')) {
            return $this->fail('Архивный модуль ZipArchive недоступен');
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($packageFile);
        if ($openResult !== true) {
            return $this->fail('Не удалось открыть архив пакета');
        }

        try {
            $manifestRaw = $zip->getFromName('package.json');
            if ($manifestRaw === false || $manifestRaw === '') {
                return $this->fail('В пакете отсутствует package.json');
            }

            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                return $this->fail('package.json имеет некорректный JSON');
            }

            $validation = $this->validateManifest($manifest);
            if ($validation !== true) {
                return $this->fail($validation);
            }

            $entries = [];
            $payloadPrefix = 'payload/';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name === '') {
                    continue;
                }
                $name = str_replace('\\', '/', $name);
                $entries[] = $name;
                if ($this->isUnsafeEntryName($name)) {
                    return $this->fail('Архив содержит небезопасные пути');
                }
                if ($name !== 'package.json' && !str_starts_with($name, $payloadPrefix)) {
                    return $this->fail('Пакет может содержать только package.json и payload/');
                }
            }

            if (!$this->hasPayloadContent($entries)) {
                return $this->fail('В пакете отсутствует содержимое payload/');
            }

            $paths = $manifest['paths'] ?? [];
            if (!is_array($paths) || $paths === []) {
                return $this->fail('В package.json отсутствует список paths');
            }

            foreach ($paths as $path) {
                $path = trim((string)$path, '/');
                if ($path === '') {
                    continue;
                }
                if (!$this->hasPathUnderPayload($entries, $path)) {
                    return $this->fail('Путь из package.json не найден в payload/: ' . $path);
                }
            }

            return [
                'success' => true,
                'message' => 'Пакет соответствует контракту Randee Package',
                'manifest' => $manifest,
                'entries' => $entries,
                'sha256' => hash_file('sha256', $packageFile) ?: '',
                'size' => filesize($packageFile) ?: 0,
            ];
        } finally {
            $zip->close();
        }
    }

    private function validateManifest(array $manifest): bool|string
    {
        $required = [
            'format',
            'format_version',
            'product_id',
            'type',
            'version',
            'channel',
            'release_tag',
            'build_number',
            'install_root',
            'paths',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $manifest) || $manifest[$field] === '' || $manifest[$field] === null) {
                return 'В package.json отсутствует обязательное поле: ' . $field;
            }
        }

        if ((string)$manifest['format'] !== 'randee-package') {
            return 'Неверный format в package.json';
        }

        if ((string)$manifest['install_root'] !== 'payload') {
            return 'install_root должен быть payload';
        }

        if (!in_array((string)$manifest['type'], self::ALLOWED_TYPES, true)) {
            return 'Неподдерживаемый type в package.json';
        }

        if (!in_array((string)$manifest['channel'], self::ALLOWED_CHANNELS, true)) {
            return 'Неподдерживаемый channel в package.json';
        }

        if (!preg_match('~^[a-z0-9._-]+$~i', (string)$manifest['product_id'])) {
            return 'Некорректный product_id';
        }

        if (!preg_match('~^[0-9A-Za-z._-]+$~', (string)$manifest['version'])) {
            return 'Некорректная version';
        }

        if (!preg_match('~^[0-9A-Za-z._-]+$~', (string)$manifest['release_tag'])) {
            return 'Некорректный release_tag';
        }

        if (!preg_match('~^[0-9A-Za-z._-]+$~', (string)$manifest['build_number'])) {
            return 'Некорректный build_number';
        }

        return true;
    }

    private function hasPayloadContent(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 'payload/') && !str_ends_with($entry, '/')) {
                return true;
            }
        }

        return false;
    }

    private function hasPathUnderPayload(array $entries, string $path): bool
    {
        $prefix = 'payload/' . ltrim($path, '/');
        foreach ($entries as $entry) {
            if ($entry === $prefix || str_starts_with($entry, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isUnsafeEntryName(string $name): bool
    {
        if (str_starts_with($name, '/')) {
            return true;
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
