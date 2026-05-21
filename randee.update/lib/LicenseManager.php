<?php

declare(strict_types=1);

namespace Randee\Update;

use Bitrix\Main\Config\Option;

final class LicenseManager
{
    private string $moduleId;

    public function __construct(string $moduleId = 'randee.update')
    {
        $this->moduleId = $moduleId;
    }

    public function getSettings(): array
    {
        return [
            'server_url' => $this->get('marketplace_server_url', 'https://updates.c0l.ru'),
            'license_key' => $this->get('marketplace_license_key', ''),
            'site_uid' => $this->get('marketplace_site_uid', $this->defaultSiteUid()),
            'channel' => $this->get('marketplace_channel', 'stable'),
            'show_beta' => $this->get('marketplace_show_beta', 'N') === 'Y',
            'enable_rollback' => $this->get('marketplace_enable_rollback', 'Y') === 'Y',
            'auto_check_updates' => $this->get('marketplace_auto_check_updates', 'N') === 'Y',
            'temp_dir' => $this->get('marketplace_temp_dir', ''),
            'last_sync_at' => $this->get('marketplace_last_sync_at', ''),
            'last_activation_raw' => $this->get('marketplace_last_activation_raw', ''),
            'last_catalog_raw' => $this->get('marketplace_last_catalog_raw', ''),
            'last_download_raw' => $this->get('marketplace_last_download_raw', ''),
            'last_install_state_raw' => $this->get('marketplace_last_install_state_raw', ''),
            'last_operation_raw' => $this->get('marketplace_last_operation_raw', ''),
            'selected_product_id' => $this->get('marketplace_selected_product_id', ''),
            'last_package_file' => $this->get('marketplace_last_package_file', ''),
            'last_package_sha256' => $this->get('marketplace_last_package_sha256', ''),
            'installed_version' => $this->get('marketplace_installed_version', ''),
            'installed_release_tag' => $this->get('marketplace_installed_release_tag', ''),
            'installed_build_number' => $this->get('marketplace_installed_build_number', ''),
            'installed_at' => $this->get('marketplace_installed_at', ''),
        ];
    }

    public function saveSettings(array $settings): void
    {
        $this->set('marketplace_server_url', trim((string)($settings['server_url'] ?? 'https://updates.c0l.ru')));
        $this->set('marketplace_license_key', trim((string)($settings['license_key'] ?? '')));
        $this->set('marketplace_site_uid', trim((string)($settings['site_uid'] ?? $this->defaultSiteUid())));
        $this->set('marketplace_channel', trim((string)($settings['channel'] ?? 'stable')) ?: 'stable');
        $this->set('marketplace_show_beta', !empty($settings['show_beta']) ? 'Y' : 'N');
        $this->set('marketplace_enable_rollback', !empty($settings['enable_rollback']) ? 'Y' : 'N');
        $this->set('marketplace_auto_check_updates', !empty($settings['auto_check_updates']) ? 'Y' : 'N');
        $this->set('marketplace_temp_dir', trim((string)($settings['temp_dir'] ?? '')));
    }

    public function buildActivationPayload(): array
    {
        return [
            'license_key' => $this->getLicenseKey(),
            'domain' => $this->currentDomain(),
            'site_uid' => $this->getSiteUid(),
            'bitrix_version' => $this->getBitrixVersion(),
            'php_version' => PHP_VERSION,
            'module_version' => $this->getModuleVersion(),
            'channel' => $this->getChannel(),
        ];
    }

    public function getServerUrl(): string
    {
        return rtrim($this->get('marketplace_server_url', 'https://updates.c0l.ru'), '/');
    }

    public function getLicenseKey(): string
    {
        return $this->get('marketplace_license_key', '');
    }

    public function getSiteUid(): string
    {
        $value = trim($this->get('marketplace_site_uid', ''));
        return $value !== '' ? $value : $this->defaultSiteUid();
    }

    public function getChannel(): string
    {
        $value = trim($this->get('marketplace_channel', 'stable'));
        return $value !== '' ? $value : 'stable';
    }

    public function shouldShowBeta(): bool
    {
        return $this->get('marketplace_show_beta', 'N') === 'Y';
    }

    public function isRollbackEnabled(): bool
    {
        return $this->get('marketplace_enable_rollback', 'Y') === 'Y';
    }

    public function isConfigured(): bool
    {
        return $this->getServerUrl() !== '' && $this->getLicenseKey() !== '';
    }

    public function recordJson(string $key, array $data): void
    {
        $this->set($key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function recordValue(string $key, string $value): void
    {
        $this->set($key, $value);
    }

    public function decodeJson(string $key): array
    {
        $raw = $this->get($key, '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function get(string $key, string $default = ''): string
    {
        return (string)Option::get($this->moduleId, $key, $default);
    }

    private function set(string $key, string $value): void
    {
        Option::set($this->moduleId, $key, $value);
    }

    private function defaultSiteUid(): string
    {
        return trim((string)($_SERVER['HTTP_HOST'] ?? 'site-' . substr(md5((string)($_SERVER['DOCUMENT_ROOT'] ?? '')), 0, 8)));
    }

    private function currentDomain(): string
    {
        return trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    private function getBitrixVersion(): string
    {
        if (defined('SM_VERSION')) {
            return (string)SM_VERSION;
        }

        return (string)Option::get('main', 'version', '');
    }

    private function getModuleVersion(): string
    {
        $versionFile = __DIR__ . '/../install/version.php';
        if (!is_file($versionFile)) {
            return '';
        }

        $arModuleVersion = [];
        include $versionFile;

        return (string)($arModuleVersion['VERSION'] ?? '');
    }
}
