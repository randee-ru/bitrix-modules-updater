<?php

use Bitrix\Main\Loader;
use Randee\Update\LicenseManager;
use Randee\Update\MarketplaceClient;
use Randee\Update\PackageInstaller;
use Randee\Update\PackageValidator;
use Randee\Update\ProductRegistry;
use Randee\Update\RollbackManager;
use Randee\Update\UpdateLogger;
use Randee\Update\VersionComparator;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'randee.update';

if (!Loader::includeModule($moduleId)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Модуль randee.update не установлен',
        'TYPE' => 'ERROR',
    ]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

global $APPLICATION;
if ($APPLICATION->GetGroupRight($moduleId) < 'W') {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage([
        'MESSAGE' => 'Недостаточно прав для работы с randee.update',
        'TYPE' => 'ERROR',
    ]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}

function randee_update_marketplace_is_allowed(array $product): bool
{
    if (array_key_exists('allowed', $product)) {
        return $product['allowed'] !== false;
    }

    if (array_key_exists('licensed', $product)) {
        return (bool)$product['licensed'];
    }

    return true;
}

function randee_update_marketplace_badge(string $text, string $tone = 'neutral'): string
{
    return '<span class="randee-marketplace-badge randee-marketplace-badge--' . htmlspecialcharsbx($tone) . '">' . htmlspecialcharsbx($text) . '</span>';
}

function randee_update_marketplace_value(string $value, string $fallback = '—'): string
{
    return $value !== '' ? htmlspecialcharsbx($value) : $fallback;
}

function randee_update_marketplace_time(string $value): string
{
    return $value !== '' ? htmlspecialcharsbx($value) : '—';
}

function randee_update_marketplace_set_flash_notice(string $type, string $title, string $message, array $details = []): void
{
    $_SESSION['randee_update_marketplace_flash_notice'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'details' => $details,
    ];
}

function randee_update_marketplace_is_ajax_request(): bool
{
    return (string)($_POST['randee_ajax'] ?? '') === 'Y'
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function randee_update_marketplace_finish_request(array $payload, bool $ajax = false): void
{
    if ($ajax) {
        global $APPLICATION;
        $APPLICATION->RestartBuffer();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        die();
    }
}

function randee_update_marketplace_gallery_items(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $items[] = $item;
        }
    }

    return $items;
}

function randee_update_marketplace_resolve_media_url(string $url, string $baseUrl): string
{
    $url = trim($url);
    $baseUrl = trim($baseUrl);

    if ($url === '') {
        return '';
    }

    if ($baseUrl === '') {
        return $url;
    }

    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $url) || str_starts_with($url, 'data:')) {
        return $url;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function randee_update_marketplace_product_type_label(array $product): string
{
    $type = strtolower(trim((string)($product['type'] ?? $product['kind'] ?? $product['product_type'] ?? '')));
    return match ($type) {
        'module' => 'Модуль',
        'component' => 'Компонент',
        'template' => 'Шаблон',
        'solution' => 'Решение',
        default => $type !== '' ? ucfirst($type) : '—',
    };
}

function randee_update_marketplace_candidate_paths(array $product): array
{
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $productId = trim((string)($product['product_id'] ?? ''));
    $type = strtolower(trim((string)($product['type'] ?? ($product['kind'] ?? ''))));
    $paths = [];

    if ($docRoot === '' || $productId === '') {
        return [];
    }

    if ($type === 'module') {
        $paths[] = $docRoot . '/local/modules/' . $productId;
    } elseif ($type === 'component') {
        $segments = explode('.', $productId, 2);
        if (count($segments) === 2) {
            $paths[] = $docRoot . '/local/components/' . $segments[0] . '/' . $segments[1];
        }
        $paths[] = $docRoot . '/local/components/' . str_replace('.', '/', $productId);
    } elseif ($type === 'template') {
        $paths[] = $docRoot . '/local/templates/' . $productId;
        $paths[] = $docRoot . '/local/templates/' . str_replace('.', '/', $productId);
    } else {
        $paths[] = $docRoot . '/local/modules/' . $productId;
        $paths[] = $docRoot . '/local/components/' . str_replace('.', '/', $productId);
        $paths[] = $docRoot . '/local/templates/' . str_replace('.', '/', $productId);
    }

    return array_values(array_unique(array_filter($paths)));
}

function randee_update_marketplace_first_existing_path(array $paths): ?string
{
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path !== '' && (is_dir($path) || is_file($path))) {
            return $path;
        }
    }

    return null;
}

function randee_update_marketplace_read_version_from_path(string $path): string
{
    $path = rtrim(trim($path), '/');
    if ($path === '' || !is_dir($path)) {
        return '';
    }

    $candidates = [
        $path . '/install/version.php',
        $path . '/version.php',
    ];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $content = @file_get_contents($candidate);
        if ($content === false) {
            continue;
        }

        if (preg_match("~'VERSION'\\s*=>\\s*'([^']+)'~", $content, $m)) {
            return trim((string)$m[1]);
        }
    }

    return '';
}

function randee_update_marketplace_resolve_install_state(array $product, array $installedMap, array $legacyState, array $legacyValues): array
{
    $productId = trim((string)($product['product_id'] ?? ''));
    $resolved = [
        'installed' => false,
        'version' => '',
        'release_tag' => '',
        'build_number' => '',
        'installed_at' => '',
        'source' => '',
        'path' => '',
    ];

    if ($productId === '') {
        return $resolved;
    }

    $mapState = $installedMap[$productId] ?? null;
    if (is_array($mapState) && !empty($mapState['installed'])) {
        return array_merge($resolved, [
            'installed' => true,
            'version' => (string)($mapState['version'] ?? ''),
            'release_tag' => (string)($mapState['release_tag'] ?? ''),
            'build_number' => (string)($mapState['build_number'] ?? ''),
            'installed_at' => (string)($mapState['installed_at'] ?? ''),
            'source' => 'map',
            'path' => (string)($mapState['path'] ?? ''),
        ]);
    }

    if (($legacyState['product_id'] ?? '') === $productId) {
        return array_merge($resolved, [
            'installed' => true,
            'version' => (string)($legacyValues['installed_version'] ?? ''),
            'release_tag' => (string)($legacyValues['installed_release_tag'] ?? ''),
            'build_number' => (string)($legacyValues['installed_build_number'] ?? ''),
            'installed_at' => (string)($legacyValues['installed_at'] ?? ''),
            'source' => 'legacy-state',
        ]);
    }

    $path = randee_update_marketplace_first_existing_path(randee_update_marketplace_candidate_paths($product));
    if ($path !== null) {
        $version = '';
        if (strtolower(trim((string)($product['type'] ?? $product['kind'] ?? ''))) === 'module') {
            $version = randee_update_marketplace_read_version_from_path($path);
        }
        $mtime = @filemtime($path) ?: time();
        return array_merge($resolved, [
            'installed' => true,
            'version' => $version,
            'release_tag' => '',
            'build_number' => '',
            'installed_at' => date('c', $mtime),
            'source' => 'filesystem',
            'path' => $path,
        ]);
    }

    return $resolved;
}

$licenseManager = new LicenseManager($moduleId);
$settings = $licenseManager->getSettings();
$client = new MarketplaceClient(
    (string)$settings['server_url'],
    (string)$settings['license_key'],
    (string)$settings['site_uid'],
    (string)$settings['channel'],
    (string)($settings['temp_dir'] ?? '')
);
$registry = new ProductRegistry();
$validator = new PackageValidator();
$logger = new UpdateLogger();
$logFile = $logger->getLogFile();

$activationResult = $licenseManager->decodeJson('marketplace_last_activation_raw');
$catalogResult = $licenseManager->decodeJson('marketplace_last_catalog_raw');
$downloadResult = $licenseManager->decodeJson('marketplace_last_download_raw');
$lastInstallState = $licenseManager->decodeJson('marketplace_last_install_state_raw');
$lastOperation = $licenseManager->decodeJson('marketplace_last_operation_raw');
$installedProducts = $licenseManager->decodeJson('marketplace_installed_products_raw');
$installedProducts = is_array($installedProducts) ? $installedProducts : [];
$lastPackageFile = (string)$settings['last_package_file'];
$lastPackageSha256 = (string)$settings['last_package_sha256'];
$installedVersion = (string)$settings['installed_version'];
$installedReleaseTag = (string)$settings['installed_release_tag'];
$installedBuildNumber = (string)$settings['installed_build_number'];
$installedAt = (string)$settings['installed_at'];
$flashNotice = [];
$isAjaxRequest = randee_update_marketplace_is_ajax_request();
if (!empty($_SESSION['randee_update_marketplace_flash_notice']) && is_array($_SESSION['randee_update_marketplace_flash_notice'])) {
    $flashNotice = $_SESSION['randee_update_marketplace_flash_notice'];
    unset($_SESSION['randee_update_marketplace_flash_notice']);
}

$catalog = [];
$catalogError = '';
$healthResponse = [];
$healthOnline = false;
if ($licenseManager->isConfigured()) {
    $healthResponse = $client->health();
    $healthOnline = !empty($healthResponse['success']);
    $catalogResponse = $client->catalog();
    if (!empty($catalogResponse['success'])) {
        $catalog = $registry->normalizeCatalog($catalogResponse['data'] ?? []);
        $licenseManager->recordJson('marketplace_last_catalog_raw', $catalogResponse['data'] ?? []);
        $licenseManager->recordValue('marketplace_last_sync_at', date('c'));
        $catalogResult = $catalogResponse['data'] ?? [];
    } else {
        $catalogError = (string)($catalogResponse['message'] ?? 'Не удалось получить каталог');
    }
}

// Only open the product popup when the request explicitly asks for it.
// The saved product id may still be used as a remembered context, but it must
// not auto-open the modal on page load.
$openedProductId = (string)($_REQUEST['product'] ?? '');
$selectedProductId = $openedProductId !== '' ? $openedProductId : (string)($settings['selected_product_id'] ?? '');
$selectedProduct = $selectedProductId !== '' ? $registry->selectProduct(['products' => $catalog], $selectedProductId) : null;
if (is_array($selectedProduct)) {
    $licenseManager->recordValue('marketplace_selected_product_id', $selectedProductId);
}

$selectedRelease = is_array($selectedProduct) ? $registry->latestRelease($selectedProduct) : null;
$selectedReleaseVersion = (string)($selectedRelease['version'] ?? '');
$selectedReleaseSha256 = (string)($selectedRelease['sha256'] ?? '');
$selectedReleaseId = isset($selectedRelease['id']) ? (int)$selectedRelease['id'] : null;
$selectedProductAllowed = is_array($selectedProduct) ? randee_update_marketplace_is_allowed($selectedProduct) : false;
$selectedProductName = is_array($selectedProduct) ? (string)($selectedProduct['name'] ?? $selectedProductId) : '';
$selectedProductKind = is_array($selectedProduct) ? randee_update_marketplace_product_type_label($selectedProduct) : '—';
$selectedProductPageTitle = is_array($selectedProduct) ? (string)($selectedProduct['page_title'] ?? '') : '';
$selectedProductPageDescription = is_array($selectedProduct) ? (string)($selectedProduct['page_description'] ?? '') : '';
$selectedProductPageInstallation = is_array($selectedProduct) ? (string)($selectedProduct['page_installation'] ?? '') : '';
$selectedProductPageSettings = is_array($selectedProduct) ? (string)($selectedProduct['page_settings'] ?? '') : '';
$selectedProductPageVideoUrl = is_array($selectedProduct) ? (string)($selectedProduct['page_video_url'] ?? '') : '';
$selectedProductPageGallery = is_array($selectedProduct) ? randee_update_marketplace_gallery_items((string)($selectedProduct['page_gallery_json'] ?? '')) : [];
$selectedProductPageCoverUrl = is_array($selectedProduct)
    ? trim((string)($selectedProduct['page_image_url'] ?? $selectedProduct['page_cover_url'] ?? ''))
    : '';
if ($selectedProductPageCoverUrl === '' && $selectedProductPageGallery !== []) {
    $selectedProductPageCoverUrl = (string)$selectedProductPageGallery[0];
}
$selectedInstallState = is_array($selectedProduct)
    ? randee_update_marketplace_resolve_install_state($selectedProduct, $installedProducts, $lastInstallState, [
        'installed_version' => $installedVersion,
        'installed_release_tag' => $installedReleaseTag,
        'installed_build_number' => $installedBuildNumber,
        'installed_at' => $installedAt,
    ])
    : [];
$selectedInstalled = (bool)($selectedInstallState['installed'] ?? false);
$selectedInstalledVersion = (string)($selectedInstallState['version'] ?? '');
$selectedInstalledReleaseTag = (string)($selectedInstallState['release_tag'] ?? '');
$selectedInstalledBuildNumber = (string)($selectedInstallState['build_number'] ?? '');
$selectedInstalledAt = (string)($selectedInstallState['installed_at'] ?? '');
$selectedUpdateAvailable = false;
if ($selectedInstalledVersion !== '' && $selectedReleaseVersion !== '') {
    $selectedUpdateAvailable = VersionComparator::isUpdateAvailable($selectedInstalledVersion, $selectedReleaseVersion);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && check_bitrix_sessid()) {
    $licenseManager->saveSettings([
        'server_url' => (string)($_POST['server_url'] ?? ''),
        'license_key' => (string)($_POST['license_key'] ?? ''),
        'site_uid' => (string)($_POST['site_uid'] ?? ''),
        'channel' => (string)($_POST['channel'] ?? 'stable'),
        'temp_dir' => (string)($_POST['temp_dir'] ?? ''),
        'show_beta' => isset($_POST['show_beta']) && $_POST['show_beta'] === 'Y',
        'enable_rollback' => isset($_POST['enable_rollback']) && $_POST['enable_rollback'] === 'Y',
        'auto_check_updates' => isset($_POST['auto_check_updates']) && $_POST['auto_check_updates'] === 'Y',
    ]);
    LocalRedirect($APPLICATION->GetCurPageParam('saved=Y&lang=' . LANGUAGE_ID, ['saved', 'lang']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_license']) && check_bitrix_sessid()) {
    $response = $client->activateLicense($licenseManager->buildActivationPayload());
    $activationResult = $response['data'] ?? $response;
    $licenseManager->recordJson('marketplace_last_activation_raw', $activationResult);
    $licenseManager->recordValue('marketplace_last_sync_at', date('c'));
    $logger->write([
        'event' => 'marketplace_activate_license',
        'response' => $activationResult,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_latest']) && check_bitrix_sessid() && $selectedProductId !== '') {
    try {
        $download = $client->downloadPackage($selectedProductId, $selectedReleaseSha256 !== '' ? $selectedReleaseSha256 : null, $selectedReleaseId);
        $downloadResult = $download;
        $licenseManager->recordJson('marketplace_last_download_raw', $download);
        $licenseManager->recordValue('marketplace_last_sync_at', date('c'));

        if (!empty($download['success']) && !empty($download['file'])) {
            $lastPackageFile = (string)$download['file'];
            $lastPackageSha256 = (string)($download['sha256'] ?? '');
            $licenseManager->recordValue('marketplace_last_package_file', $lastPackageFile);
            $licenseManager->recordValue('marketplace_last_package_sha256', $lastPackageSha256);
        }

        $logger->write([
            'event' => 'marketplace_download',
            'product_id' => $selectedProductId,
            'result' => $download,
        ]);

        $downloadSuccess = !empty($download['success']);
        $downloadTitle = $downloadSuccess ? 'Пакет скачан' : 'Не удалось скачать пакет';
        $downloadMessage = $downloadSuccess
            ? 'Архив обновления сохранён и готов к установке.'
            : (string)($download['message'] ?? 'Запрос на скачивание завершился с ошибкой.');

        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request([
                'success' => $downloadSuccess,
                'type' => $downloadSuccess ? 'success' : 'danger',
                'title' => $downloadTitle,
                'message' => $downloadMessage,
                'details' => $download,
                'reload' => false,
            ], true);
        }

        randee_update_marketplace_set_flash_notice(
            $downloadSuccess ? 'success' : 'danger',
            $downloadTitle,
            $downloadMessage,
            $download
        );
    } catch (\Throwable $e) {
        $download = [
            'success' => false,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ];
        $downloadResult = $download;
        $licenseManager->recordJson('marketplace_last_download_raw', $download);
        $licenseManager->recordValue('marketplace_last_sync_at', date('c'));
        $logger->write([
            'event' => 'marketplace_download_error',
            'product_id' => $selectedProductId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request([
                'success' => false,
                'type' => 'danger',
                'title' => 'Не удалось скачать пакет',
                'message' => $e->getMessage(),
                'details' => $download,
                'reload' => false,
            ], true);
        }

        randee_update_marketplace_set_flash_notice(
            'danger',
            'Не удалось скачать пакет',
            $e->getMessage(),
            $download
        );
    }

    LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_latest']) && check_bitrix_sessid()) {
    try {
        if ($lastPackageFile === '') {
            $download = $client->downloadPackage($selectedProductId, $selectedReleaseSha256 !== '' ? $selectedReleaseSha256 : null, $selectedReleaseId);
            $downloadResult = $download;
            $licenseManager->recordJson('marketplace_last_download_raw', $download);
            if (!empty($download['success']) && !empty($download['file'])) {
                $lastPackageFile = (string)$download['file'];
                $lastPackageSha256 = (string)($download['sha256'] ?? '');
                $licenseManager->recordValue('marketplace_last_package_file', $lastPackageFile);
                $licenseManager->recordValue('marketplace_last_package_sha256', $lastPackageSha256);
            }
        }

        if ($lastPackageFile === '') {
            $lastOperation = [
                'event' => 'install',
                'success' => false,
                'message' => 'Не удалось получить пакет',
                'at' => date('c'),
            ];
            $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);
            $payload = [
                'success' => false,
                'type' => 'danger',
                'title' => 'Установка не началась',
                'message' => 'Не удалось получить пакет для установки.',
                'details' => $lastOperation,
                'reload' => false,
            ];
            if ($isAjaxRequest) {
                randee_update_marketplace_finish_request($payload, true);
            }
            randee_update_marketplace_set_flash_notice('danger', 'Установка не началась', 'Не удалось получить пакет для установки.', $lastOperation);
            LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
        }

        $inspection = $validator->inspect($lastPackageFile);
        if (empty($inspection['success'])) {
            $lastOperation = [
                'event' => 'install',
                'success' => false,
                'message' => (string)($inspection['message'] ?? 'Пакет не прошёл проверку'),
                'details' => $inspection,
                'at' => date('c'),
            ];
            $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);
            $payload = [
                'success' => false,
                'type' => 'danger',
                'title' => 'Пакет не прошёл проверку',
                'message' => (string)($inspection['message'] ?? 'Пакет не прошёл проверку'),
                'details' => $inspection,
                'reload' => false,
            ];
            if ($isAjaxRequest) {
                randee_update_marketplace_finish_request($payload, true);
            }
            randee_update_marketplace_set_flash_notice('danger', 'Пакет не прошёл проверку', (string)($inspection['message'] ?? 'Пакет не прошёл проверку'), $inspection);
            LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
        }

        $installer = new PackageInstaller(null, (string)($settings['temp_dir'] ?? ''));
        $install = $installer->install($lastPackageFile);
        $logger->write([
            'event' => 'marketplace_install',
            'product_id' => $selectedProductId,
            'result' => $install,
        ]);

        if (!empty($install['success'])) {
            $licenseManager->recordValue('marketplace_installed_version', (string)($selectedRelease['version'] ?? ''));
            $licenseManager->recordValue('marketplace_installed_release_tag', (string)($selectedRelease['release_tag'] ?? ''));
            $licenseManager->recordValue('marketplace_installed_build_number', (string)($selectedRelease['build_number'] ?? ''));
            $licenseManager->recordValue('marketplace_installed_at', date('c'));
            $installedProducts[$selectedProductId] = [
                'product_id' => $selectedProductId,
                'installed' => true,
                'version' => (string)($selectedRelease['version'] ?? ''),
                'release_tag' => (string)($selectedRelease['release_tag'] ?? ''),
                'build_number' => (string)($selectedRelease['build_number'] ?? ''),
                'installed_at' => date('c'),
                'package_file' => $lastPackageFile,
                'package_sha256' => $lastPackageSha256,
                'backup_dir' => (string)($install['backup_dir'] ?? ''),
                'extract_dir' => (string)($install['extract_dir'] ?? ''),
                'path' => '',
                'source' => 'install',
            ];
            $licenseManager->recordJson('marketplace_installed_products_raw', $installedProducts);
            $licenseManager->recordJson('marketplace_last_install_state_raw', [
                'product_id' => $selectedProductId,
                'package_file' => $lastPackageFile,
                'package_sha256' => $lastPackageSha256,
                'backup_dir' => $install['backup_dir'] ?? '',
                'extract_dir' => $install['extract_dir'] ?? '',
                'installed_files' => $install['installed_files'] ?? [],
                'backed_up_files' => $install['backed_up_files'] ?? [],
                'backup_map' => $install['backup_map'] ?? [],
                'pre_install_version' => $installedVersion,
                'pre_install_release_tag' => $installedReleaseTag,
                'pre_install_build_number' => $installedBuildNumber,
                'pre_install_at' => $installedAt,
            ]);
            $installedVersion = (string)($selectedRelease['version'] ?? '');
            $installedReleaseTag = (string)($selectedRelease['release_tag'] ?? '');
            $installedBuildNumber = (string)($selectedRelease['build_number'] ?? '');
            $installedAt = date('c');
        }

        $lastOperation = [
            'event' => 'install',
            'success' => !empty($install['success']),
            'message' => (string)($install['message'] ?? 'Установка завершена'),
            'details' => $install,
            'at' => date('c'),
        ];
        $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);

        $payload = [
            'success' => !empty($install['success']),
            'type' => !empty($install['success']) ? 'success' : 'danger',
            'title' => !empty($install['success']) ? 'Установлено' : 'Установка не удалась',
            'message' => !empty($install['success'])
                ? 'Обновление установлено успешно.'
                : (string)($install['message'] ?? 'Не удалось установить пакет'),
            'details' => $install,
            'reload' => !empty($install['success']),
        ];
        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request($payload, true);
        }

        if (!empty($install['success'])) {
            randee_update_marketplace_set_flash_notice(
                'success',
                'Установлено',
                'Обновление установлено успешно.',
                $install
            );
        } else {
            randee_update_marketplace_set_flash_notice(
                'danger',
                'Установка не удалась',
                (string)($install['message'] ?? 'Не удалось установить пакет'),
                $install
            );
        }
        LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
    } catch (\Throwable $e) {
        $lastOperation = [
            'event' => 'install',
            'success' => false,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'at' => date('c'),
        ];
        $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);
        $logger->write([
            'event' => 'marketplace_install_error',
            'product_id' => $selectedProductId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
        $payload = [
            'success' => false,
            'type' => 'danger',
            'title' => 'Установка не удалась',
            'message' => $e->getMessage(),
            'details' => $lastOperation,
            'reload' => false,
        ];
        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request($payload, true);
        }
        randee_update_marketplace_set_flash_notice('danger', 'Установка не удалась', $e->getMessage(), $lastOperation);
        LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rollback_latest']) && check_bitrix_sessid()) {
    try {
        if (!$licenseManager->isRollbackEnabled()) {
            $lastOperation = [
                'event' => 'rollback',
                'success' => false,
                'message' => 'Rollback отключен в настройках',
                'at' => date('c'),
            ];
        } else {
            $rollbackState = $licenseManager->decodeJson('marketplace_last_install_state_raw');
            if ($rollbackState === []) {
                $lastOperation = [
                    'event' => 'rollback',
                    'success' => false,
                    'message' => 'Нет состояния для отката',
                    'at' => date('c'),
                ];
            } else {
                $rollbackManager = new RollbackManager();
                $rollback = $rollbackManager->rollback($rollbackState);
                $lastOperation = [
                    'event' => 'rollback',
                    'success' => !empty($rollback['success']),
                    'message' => (string)($rollback['message'] ?? 'Rollback выполнен'),
                    'details' => $rollback,
                    'at' => date('c'),
                ];

                if (!empty($rollback['success'])) {
                    $licenseManager->recordValue('marketplace_installed_version', (string)($rollbackState['pre_install_version'] ?? ''));
                    $licenseManager->recordValue('marketplace_installed_release_tag', (string)($rollbackState['pre_install_release_tag'] ?? ''));
                    $licenseManager->recordValue('marketplace_installed_build_number', (string)($rollbackState['pre_install_build_number'] ?? ''));
                    $licenseManager->recordValue('marketplace_installed_at', (string)($rollbackState['pre_install_at'] ?? ''));
                    $licenseManager->recordValue('marketplace_last_install_state_raw', '');
                    if ($selectedProductId !== '') {
                        $installedProducts[$selectedProductId] = [
                            'product_id' => $selectedProductId,
                            'installed' => true,
                            'version' => (string)($rollbackState['pre_install_version'] ?? ''),
                            'release_tag' => (string)($rollbackState['pre_install_release_tag'] ?? ''),
                            'build_number' => (string)($rollbackState['pre_install_build_number'] ?? ''),
                            'installed_at' => (string)($rollbackState['pre_install_at'] ?? ''),
                            'package_file' => '',
                            'package_sha256' => '',
                            'backup_dir' => '',
                            'extract_dir' => '',
                            'path' => '',
                            'source' => 'rollback',
                        ];
                        $licenseManager->recordJson('marketplace_installed_products_raw', $installedProducts);
                    }
                }
            }
        }
        $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);
        $payload = [
            'success' => !empty($lastOperation['success']),
            'type' => !empty($lastOperation['success']) ? 'success' : 'danger',
            'title' => !empty($lastOperation['success']) ? 'Откат выполнен' : 'Откат не выполнен',
            'message' => (string)($lastOperation['message'] ?? ''),
            'details' => $lastOperation,
            'reload' => !empty($lastOperation['success']),
        ];
        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request($payload, true);
        }
        randee_update_marketplace_set_flash_notice(
            !empty($lastOperation['success']) ? 'success' : 'danger',
            !empty($lastOperation['success']) ? 'Откат выполнен' : 'Откат не выполнен',
            (string)($lastOperation['message'] ?? ''),
            $lastOperation
        );
        LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
    } catch (\Throwable $e) {
        $lastOperation = [
            'event' => 'rollback',
            'success' => false,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'at' => date('c'),
        ];
        $licenseManager->recordJson('marketplace_last_operation_raw', $lastOperation);
        $payload = [
            'success' => false,
            'type' => 'danger',
            'title' => 'Откат не выполнен',
            'message' => $e->getMessage(),
            'details' => $lastOperation,
            'reload' => false,
        ];
        if ($isAjaxRequest) {
            randee_update_marketplace_finish_request($payload, true);
        }
        randee_update_marketplace_set_flash_notice('danger', 'Откат не выполнен', $e->getMessage(), $lastOperation);
        LocalRedirect($APPLICATION->GetCurPageParam('', ['saved', 'lang']));
    }
}

$settings = $licenseManager->getSettings();
$marketplaceMediaBaseUrl = trim((string)($settings['server_url'] ?? ''));
if ($marketplaceMediaBaseUrl === '') {
    $marketplaceMediaBaseUrl = 'https://updates.c0l.ru';
}
$selectedProductPageCoverUrl = randee_update_marketplace_resolve_media_url($selectedProductPageCoverUrl, $marketplaceMediaBaseUrl);
if ($selectedProductPageGallery !== []) {
    $selectedProductPageGallery = array_values(array_filter(array_map(
        static fn(string $item): string => randee_update_marketplace_resolve_media_url($item, $marketplaceMediaBaseUrl),
        $selectedProductPageGallery
    )));
}
$selectedProduct = $selectedProductId !== '' ? $registry->selectProduct(['products' => $catalog], $selectedProductId) : null;
$selectedRelease = is_array($selectedProduct) ? $registry->latestRelease($selectedProduct) : null;
$selectedReleaseVersion = (string)($selectedRelease['version'] ?? '');
$selectedProductAllowed = is_array($selectedProduct) ? randee_update_marketplace_is_allowed($selectedProduct) : false;
$selectedUpdateAvailable = false;
if ($installedVersion !== '' && $selectedReleaseVersion !== '') {
    $selectedUpdateAvailable = VersionComparator::isUpdateAvailable($installedVersion, $selectedReleaseVersion);
}

$ownedProducts = [];
$lockedProducts = [];
foreach ($catalog as $product) {
    if (randee_update_marketplace_is_allowed((array)$product)) {
        $ownedProducts[] = $product;
    } else {
        $lockedProducts[] = $product;
    }
}

$connectionReady = $settings['server_url'] !== '' && $settings['license_key'] !== '';
$licenseReady = $settings['license_key'] !== '';
$settingsEditMode = (string)($_REQUEST['edit_settings'] ?? '') === 'Y';
$settingsEditUrl = $APPLICATION->GetCurPageParam('edit_settings=Y', ['saved', 'lang', 'product', 'edit_settings']);
$settingsCloseUrl = $APPLICATION->GetCurPageParam('', ['saved', 'lang', 'product', 'edit_settings']);
$catalogCount = count($catalog);
$ownedCount = count($ownedProducts);
$lockedCount = count($lockedProducts);
$lastSync = (string)$settings['last_sync_at'];
$lastSyncLabel = $lastSync !== '' ? $lastSync : '—';
$connectionLabel = $healthOnline || !empty($catalog) ? 'Онлайн' : 'Офлайн';
$connectionTone = $healthOnline || !empty($catalog) ? 'success' : 'danger';
$licenseLabel = $licenseReady ? 'Лицензия сохранена' : 'Лицензия не указана';
$licenseTone = $licenseReady ? 'success' : 'warning';
$catalogLabel = $catalogCount > 0 ? (string)$catalogCount : 'Пусто';
$catalogTone = $catalogCount > 0 ? 'success' : 'warning';
$installLabel = $selectedInstalled ? 'Установлено' : 'Не установлено';
$installTone = $selectedInstalled ? 'success' : 'neutral';
$selectedReleaseLabel = $selectedReleaseVersion !== '' ? $selectedReleaseVersion : '—';
$selectedReleaseTone = $selectedUpdateAvailable ? 'warning' : 'success';
$selectedAccessLabel = $selectedProductAllowed ? 'Доступ открыт' : 'Нужна лицензия';
$selectedAccessTone = $selectedProductAllowed ? 'success' : 'danger';

$APPLICATION->SetTitle('Randee Update Marketplace');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
    .randee-marketplace {
        --rm-bg: #f6f8fb;
        --rm-card: #ffffff;
        --rm-border: #dfe5ee;
        --rm-text: #1f2937;
        --rm-muted: #667085;
        --rm-blue: #2563eb;
        --rm-blue-soft: #eaf1ff;
        --rm-green: #0f9d58;
        --rm-green-soft: #e9f8ef;
        --rm-amber: #b26a00;
        --rm-amber-soft: #fff4df;
        --rm-red: #c02d2d;
        --rm-red-soft: #fdecec;
        color: var(--rm-text);
    }
    .randee-marketplace * {
        box-sizing: border-box;
    }
    .randee-marketplace-shell {
        background: linear-gradient(180deg, #fbfcfe 0%, #f6f8fb 100%);
        border: 1px solid var(--rm-border);
        border-radius: 16px;
        padding: 24px;
    }
    .randee-marketplace-hero {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 18px;
    }
    .randee-marketplace-kicker {
        color: var(--rm-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 8px;
    }
    .randee-marketplace-title {
        margin: 0 0 10px;
        font-size: 28px;
        line-height: 1.2;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    .randee-marketplace-subtitle {
        margin: 0;
        max-width: 920px;
        color: var(--rm-muted);
        font-size: 14px;
        line-height: 1.6;
    }
    .randee-marketplace-badges {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }
    .randee-marketplace-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        border: 1px solid transparent;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }
    .randee-marketplace-badge-link {
        display: inline-flex;
        text-decoration: none;
        color: inherit;
    }
    .randee-marketplace-badge--success { background: var(--rm-green-soft); color: var(--rm-green); border-color: #cfeedd; }
    .randee-marketplace-badge--warning { background: var(--rm-amber-soft); color: var(--rm-amber); border-color: #f4ddb4; }
    .randee-marketplace-badge--danger { background: var(--rm-red-soft); color: var(--rm-red); border-color: #f3c6c6; }
    .randee-marketplace-badge--info { background: var(--rm-blue-soft); color: var(--rm-blue); border-color: #cfe0ff; }
    .randee-marketplace-badge--neutral { background: #eef2f7; color: #475569; border-color: #d8e0ea; }
    .randee-marketplace-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin: 18px 0 22px;
    }
    .randee-marketplace-stat {
        background: var(--rm-card);
        border: 1px solid var(--rm-border);
        border-radius: 14px;
        padding: 14px 16px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .randee-marketplace-stat__label {
        color: var(--rm-muted);
        font-size: 12px;
        margin-bottom: 8px;
    }
    .randee-marketplace-stat__value {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 19px;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 6px;
    }
    .randee-marketplace-stat__hint {
        font-size: 12px;
        color: var(--rm-muted);
        line-height: 1.4;
    }
    .randee-marketplace-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.9fr);
        gap: 16px;
        align-items: start;
    }
    .randee-marketplace-stack {
        display: grid;
        gap: 16px;
    }
    .randee-marketplace-panel {
        background: var(--rm-card);
        border: 1px solid var(--rm-border);
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .randee-marketplace-panel__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }
    .randee-marketplace-panel__title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        line-height: 1.3;
    }
    .randee-marketplace-panel__subtitle {
        margin: 6px 0 0;
        color: var(--rm-muted);
        font-size: 13px;
        line-height: 1.45;
    }
    .randee-marketplace-help {
        display: grid;
        gap: 10px;
    }
    .randee-marketplace-help__item {
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        padding: 10px 0;
        border-bottom: 1px solid #edf1f6;
    }
    .randee-marketplace-help__item:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }
    .randee-marketplace-help__index {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        background: #eff4ff;
        color: var(--rm-blue);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 13px;
    }
    .randee-marketplace-help__title {
        margin: 0 0 4px;
        font-size: 13px;
        font-weight: 700;
    }
    .randee-marketplace-help__text {
        margin: 0;
        color: var(--rm-muted);
        font-size: 13px;
        line-height: 1.5;
    }
    .randee-marketplace-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .randee-marketplace-field label {
        display: block;
        margin-bottom: 6px;
        color: var(--rm-text);
        font-size: 13px;
        font-weight: 600;
    }
    .randee-marketplace-field__hint {
        margin-top: 6px;
        color: var(--rm-muted);
        font-size: 12px;
        line-height: 1.45;
    }
    .randee-marketplace-product-modal {
        position: fixed;
        inset: 0;
        z-index: 90;
        display: none;
        align-items: flex-start;
        justify-content: center;
        padding: 92px 16px 16px;
        background: rgba(15, 23, 42, .42);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        overflow: auto;
    }
    .randee-marketplace-product-modal.is-open {
        display: flex;
    }
    .randee-marketplace-product-modal__dialog {
        width: min(1240px, 100%);
        background: rgba(255, 255, 255, .96);
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 24px;
        box-shadow: 0 32px 80px rgba(15, 23, 42, .28);
        padding: 0;
        margin: 0 auto;
        max-height: calc(100vh - 108px);
        overflow: auto;
        box-sizing: border-box;
        position: relative;
    }
    body.randee-marketplace-modal-open {
        overflow: auto;
    }
    .randee-marketplace-settings-modal {
        position: fixed;
        inset: 0;
        z-index: 95;
        display: none;
        align-items: flex-start;
        justify-content: center;
        padding: 124px 20px 20px;
        background: rgba(15, 23, 42, .42);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        overflow: auto;
    }
    .randee-marketplace-settings-modal.is-open {
        display: flex;
    }
    .randee-marketplace-settings-modal__dialog {
        width: min(1040px, 100%);
        background: rgba(255, 255, 255, .96);
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 24px;
        box-shadow: 0 32px 80px rgba(15, 23, 42, .28);
        padding: 20px;
        margin: 0 auto;
        max-height: calc(100vh - 144px);
        overflow: auto;
        box-sizing: border-box;
    }
    .randee-marketplace-settings-modal__head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 16px;
    }
    .randee-marketplace-settings-modal__close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .28);
        background: #fff;
        color: var(--rm-text);
        font-size: 20px;
        font-weight: 700;
        line-height: 1;
        text-decoration: none !important;
        font-family: inherit;
        letter-spacing: 0;
        flex: 0 0 40px;
        transform: none;
    }
    .randee-marketplace-product-modal__head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 16px;
        padding: 24px 24px 0;
    }
    .randee-marketplace-product-modal__close {
        position: fixed;
        top: 104px;
        right: 24px;
        z-index: 101;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .28);
        background: #fff;
        color: var(--rm-text);
        font-size: 24px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none !important;
        font-family: inherit;
        letter-spacing: 0;
        flex: 0 0 40px;
        transform: none;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .14);
    }
    .randee-marketplace-notice,
    .randee-marketplace-loading {
        position: fixed;
        inset: 0;
        z-index: 125;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, .44);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
    }
    .randee-marketplace-notice.is-open,
    .randee-marketplace-loading.is-open {
        display: flex;
    }
    .randee-marketplace-notice__dialog,
    .randee-marketplace-loading__dialog {
        width: min(560px, 100%);
        border-radius: 24px;
        background: rgba(255, 255, 255, .98);
        border: 1px solid rgba(148, 163, 184, .22);
        box-shadow: 0 28px 80px rgba(15, 23, 42, .28);
        overflow: hidden;
        box-sizing: border-box;
    }
    .randee-marketplace-notice__dialog {
        padding: 20px;
    }
    .randee-marketplace-loading__dialog {
        padding: 22px 24px;
        max-width: 440px;
    }
    .randee-marketplace-notice__head,
    .randee-marketplace-loading__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
    }
    .randee-marketplace-notice__icon,
    .randee-marketplace-loading__icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        flex: 0 0 auto;
    }
    .randee-marketplace-notice__icon--success { background: var(--rm-green-soft); color: var(--rm-green); }
    .randee-marketplace-notice__icon--danger { background: var(--rm-red-soft); color: var(--rm-red); }
    .randee-marketplace-notice__icon--warning { background: var(--rm-amber-soft); color: var(--rm-amber); }
    .randee-marketplace-loading__icon {
        background: var(--rm-blue-soft);
        color: var(--rm-blue);
        position: relative;
    }
    .randee-marketplace-loading__icon::after {
        content: '';
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 2px solid currentColor;
        border-top-color: transparent;
        animation: randee-marketplace-spin .8s linear infinite;
    }
    @keyframes randee-marketplace-spin {
        to { transform: rotate(360deg); }
    }
    .randee-marketplace-notice__title,
    .randee-marketplace-loading__title {
        margin: 0;
        font-size: 18px;
        line-height: 1.35;
        font-weight: 700;
    }
    .randee-marketplace-notice__text,
    .randee-marketplace-loading__text {
        margin: 8px 0 0;
        color: var(--rm-muted);
        font-size: 13px;
        line-height: 1.55;
    }
    .randee-marketplace-notice__actions,
    .randee-marketplace-loading__actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 16px;
    }
    .randee-marketplace-loading__progress {
        margin-top: 14px;
        height: 8px;
        border-radius: 999px;
        background: #eaf1ff;
        overflow: hidden;
    }
    .randee-marketplace-loading__progress > span {
        display: block;
        width: 45%;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #2f6df6, #7aa2ff);
        animation: randee-marketplace-progress 1.1s ease-in-out infinite;
    }
    @keyframes randee-marketplace-progress {
        0% { transform: translateX(-30%); }
        50% { transform: translateX(100%); }
        100% { transform: translateX(-30%); }
    }
    .randee-marketplace-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
        padding: 0 24px 24px;
    }
    .randee-marketplace-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 36px;
        padding: 0 14px;
        border-radius: 10px;
        border: 1px solid var(--rm-border);
        background: #fff;
        color: var(--rm-text);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
    }
    .randee-marketplace-btn:hover {
        border-color: #b8c7db;
        background: #fafcff;
    }
    .randee-marketplace-btn--primary {
        background: var(--rm-blue);
        color: #fff;
        border-color: var(--rm-blue);
        box-shadow: 0 8px 20px rgba(47, 109, 246, .16);
    }
    .randee-marketplace-product-modal .randee-marketplace-btn {
        min-width: 168px;
    }
    .randee-marketplace-product-modal .randee-marketplace-btn--primary {
        background: #2f6df6 !important;
        color: #fff !important;
        border-color: #2f6df6 !important;
        opacity: 1 !important;
    }
    .randee-marketplace-product-modal .randee-marketplace-btn--primary:hover {
        background: #1e55d4 !important;
        border-color: #1e55d4 !important;
    }
    .randee-marketplace-btn--primary:hover {
        background: #1e55d4;
        border-color: #1e55d4;
    }
    .randee-marketplace-btn--danger {
        background: #fff5f5;
        color: var(--rm-red);
        border-color: #f0c7c7;
    }
    .randee-marketplace-btn[disabled] {
        opacity: 1;
        background: #f8fafc;
        color: #94a3b8;
        border-color: #d9e2ef;
        cursor: not-allowed;
    }
    .randee-marketplace-btn--primary[disabled] {
        background: #2f6df6;
        color: #fff;
        border-color: #2f6df6;
        opacity: .72;
        box-shadow: 0 8px 20px rgba(47, 109, 246, .16);
    }
    .randee-marketplace-alert {
        border-radius: 12px;
        border: 1px solid #dce5f3;
        background: #f7fbff;
        padding: 12px 14px;
        color: #274066;
        font-size: 13px;
        line-height: 1.55;
        margin: 14px 24px 0;
    }
    .randee-marketplace-alert--warning {
        border-color: #f4ddb4;
        background: #fff8ea;
        color: #7c5612;
    }
    .randee-marketplace-alert--danger {
        border-color: #f1c6c6;
        background: #fff4f4;
        color: #8d2f2f;
    }
    .randee-marketplace-section-title {
        margin: 0 0 10px;
        font-size: 14px;
        font-weight: 700;
    }
    .randee-marketplace-section-note {
        margin: 0 0 12px;
        color: var(--rm-muted);
        font-size: 13px;
        line-height: 1.5;
    }
    .randee-marketplace-table-wrap {
        overflow-x: auto;
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        background: #fff;
    }
    .randee-marketplace-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 760px;
    }
    .randee-marketplace-table th,
    .randee-marketplace-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #edf1f6;
        vertical-align: top;
        text-align: left;
    }
    .randee-marketplace-table th {
        background: #f8fafc;
        color: #475467;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .randee-marketplace-table tr:hover td {
        background: #fbfdff;
    }
    .randee-marketplace-table tr.is-selected td {
        background: #f3f8ff;
    }
    .randee-marketplace-table__muted {
        color: var(--rm-muted);
        font-size: 12px;
        margin-top: 4px;
    }
    .randee-marketplace-search {
        width: 100%;
        max-width: 360px;
    }
    .randee-marketplace-product-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }
    .randee-marketplace-kv {
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        padding: 12px 14px;
        background: #fff;
    }
    .randee-marketplace-kv__label {
        color: var(--rm-muted);
        font-size: 12px;
        margin-bottom: 6px;
    }
    .randee-marketplace-kv__value {
        font-size: 14px;
        font-weight: 600;
        line-height: 1.45;
        word-break: break-word;
    }
    .randee-marketplace-technical {
        display: grid;
        gap: 10px;
    }
    .randee-marketplace-details {
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        background: #fff;
        overflow: hidden;
    }
    .randee-marketplace-details > summary {
        list-style: none;
        cursor: pointer;
        padding: 12px 14px;
        font-size: 13px;
        font-weight: 700;
        background: #f8fafc;
        user-select: none;
    }
    .randee-marketplace-details > summary::-webkit-details-marker {
        display: none;
    }
    .randee-marketplace-details__body {
        padding: 12px 14px;
        border-top: 1px solid #edf1f6;
    }
    .randee-marketplace-details pre {
        margin: 0;
        white-space: pre-wrap;
        word-break: break-word;
        background: #f8fafc;
        border: 1px solid #e5ebf3;
        border-radius: 10px;
        padding: 12px;
        font-size: 12px;
        line-height: 1.55;
    }
    .randee-marketplace-empty {
        padding: 20px;
        border: 1px dashed #d5deea;
        border-radius: 12px;
        background: #fbfcfe;
        color: var(--rm-muted);
        font-size: 13px;
    }
    .randee-marketplace-compact-list {
        display: grid;
        gap: 10px;
    }
    .randee-marketplace-compact-item {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        padding: 12px 14px;
        background: #fff;
    }
    .randee-marketplace-compact-item__left {
        min-width: 0;
    }
    .randee-marketplace-compact-item__title {
        margin: 0 0 4px;
        font-size: 13px;
        font-weight: 700;
    }
    .randee-marketplace-compact-item__text {
        margin: 0;
        color: var(--rm-muted);
        font-size: 12px;
        line-height: 1.45;
    }
    .page-media-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
    .page-media-card {
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }
    .page-media-card img,
    .page-media-card iframe {
        display: block;
        width: 100%;
        aspect-ratio: 16 / 10;
        object-fit: cover;
        border: 0;
    }
    .page-media-card__label {
        padding: 8px 10px;
        font-size: 12px;
        color: var(--rm-muted);
        border-top: 1px solid #edf1f6;
        word-break: break-word;
    }
    .page-rich-preview {
        display: grid;
        gap: 10px;
        margin-top: 10px;
    }
    .page-rich-preview__block {
        border: 1px solid #e5ebf3;
        border-radius: 12px;
        padding: 12px 14px;
        background: #fff;
    }
    .page-rich-preview__block h4 {
        margin: 0 0 6px;
        font-size: 13px;
    }
    .page-rich-preview__block p {
        margin: 0;
        font-size: 12px;
        line-height: 1.5;
        color: var(--rm-muted);
        white-space: pre-line;
    }
    .randee-marketplace-product-hero {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0;
        margin-top: 0;
        padding: 0;
        border-radius: 24px 24px 0 0;
        border: 1px solid #e5ebf3;
        background: linear-gradient(180deg, #fbfcfe 0%, #f8fbff 100%);
        overflow: hidden;
    }
    .randee-marketplace-product-hero__media {
        width: 100%;
        min-height: 320px;
        border-radius: 0;
        overflow: hidden;
        background: #edf3fb;
        border: 1px solid #dbe5f3;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .randee-marketplace-product-hero__media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .randee-marketplace-product-hero__placeholder {
        padding: 20px;
        color: #6b7280;
        font-size: 13px;
        text-align: center;
        line-height: 1.5;
    }
    .randee-marketplace-product-hero__body {
        display: grid;
        align-content: start;
        gap: 10px;
        padding: 24px 24px 14px;
    }
    .randee-marketplace-product-hero__eyebrow {
        color: var(--rm-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 700;
    }
    .randee-marketplace-product-hero__title {
        margin: 0;
        font-size: 22px;
        line-height: 1.2;
        font-weight: 800;
        letter-spacing: -0.02em;
    }
    .randee-marketplace-product-hero__text {
        margin: 0;
        color: var(--rm-text);
        font-size: 14px;
        line-height: 1.7;
        white-space: pre-wrap;
    }
    @media (max-width: 1440px) {
        .randee-marketplace-layout {
            grid-template-columns: 1fr;
        }
        .randee-marketplace-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .randee-marketplace-panel__header {
            align-items: flex-start;
        }
    }
    @media (max-width: 1200px) {
        .randee-marketplace-layout {
            grid-template-columns: 1fr;
        }
        .randee-marketplace-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .randee-marketplace-hero {
            flex-direction: column;
        }
        .randee-marketplace-badges {
            justify-content: flex-start;
        }
        .randee-marketplace-product-modal {
            padding: 92px 12px 12px;
        }
        .randee-marketplace-product-modal__dialog {
            max-height: calc(100vh - 108px);
        }
        .randee-marketplace-product-hero {
            grid-template-columns: 1fr;
        }
        .randee-marketplace-product-hero__media {
            min-height: 180px;
        }
    }
    @media (max-width: 720px) {
        .randee-marketplace-shell {
            padding: 16px;
        }
        .randee-marketplace-hero {
            gap: 12px;
            margin-bottom: 14px;
        }
        .randee-marketplace-title {
            font-size: 22px;
            line-height: 1.25;
        }
        .randee-marketplace-subtitle {
            font-size: 13px;
            line-height: 1.55;
        }
        .randee-marketplace-panel {
            padding: 14px;
        }
        .randee-marketplace-stat {
            padding: 12px 13px;
        }
        .randee-marketplace-hero {
            flex-direction: column;
        }
        .randee-marketplace-badges {
            justify-content: flex-start;
        }
        .randee-marketplace-actions {
            gap: 10px;
        }
        .randee-marketplace-btn {
            width: 100%;
        }
        .randee-marketplace-help__item {
            grid-template-columns: 24px minmax(0, 1fr);
            gap: 8px;
        }
        .randee-marketplace-help__index {
            width: 24px;
            height: 24px;
            font-size: 12px;
        }
        .randee-marketplace-stats,
        .randee-marketplace-grid-2,
        .randee-marketplace-product-grid,
        .randee-marketplace-layout {
            grid-template-columns: 1fr;
        }
        .randee-marketplace-search {
            max-width: 100%;
        }
        .randee-marketplace-table {
            min-width: 640px;
        }
        .randee-marketplace-panel__header {
            flex-direction: column;
        }
        .randee-marketplace-badges {
            width: 100%;
        }
        .randee-marketplace-product-modal {
            padding: 72px 10px 10px;
        }
        .randee-marketplace-product-modal__dialog {
            border-radius: 20px;
            max-height: calc(100vh - 84px);
        }
        .randee-marketplace-product-modal__head {
            align-items: center;
            padding: 16px 16px 0;
        }
        .randee-marketplace-product-modal__close {
            width: 36px;
            height: 36px;
            flex-basis: 36px;
            font-size: 20px;
            top: 88px;
            right: 14px;
        }
        .randee-marketplace-product-hero {
            border-radius: 20px 20px 0 0;
        }
        .randee-marketplace-product-hero__title {
            font-size: 20px;
        }
        .randee-marketplace-product-hero__media {
            min-height: 220px;
        }
        .randee-marketplace-product-hero__body,
        .randee-marketplace-actions,
        .randee-marketplace-alert {
            padding-left: 16px;
            padding-right: 16px;
        }
        .randee-marketplace-actions {
            padding-bottom: 16px;
        }
        .randee-marketplace-alert {
            margin: 14px 16px 0;
        }
        .randee-marketplace-product-modal .randee-marketplace-btn {
            min-width: 0;
            width: 100%;
        }
    }
</style>
<div class="randee-marketplace">
    <div class="randee-marketplace-shell">
        <div class="randee-marketplace-hero">
            <div>
                <div class="randee-marketplace-kicker">Клиентский центр обновлений</div>
                <h1 class="randee-marketplace-title">Randee Update Marketplace</h1>
                <p class="randee-marketplace-subtitle">
                    Один экран для подключения к <strong>updates.c0l.ru</strong>, активации лицензии, выбора продукта и установки обновлений.
                    Интерфейс собран так, чтобы человек без контекста сразу видел: что подключено, что доступно и что делать дальше.
                </p>
            </div>
            <div class="randee-marketplace-badges">
                <?= randee_update_marketplace_badge($connectionLabel, $connectionTone) ?>
                <a class="randee-marketplace-badge-link" href="<?= htmlspecialcharsbx($settingsEditUrl) ?>">
                    <?= randee_update_marketplace_badge($licenseLabel, $licenseTone) ?>
                </a>
                <?= randee_update_marketplace_badge($catalogLabel . ' продуктов', $catalogTone) ?>
            </div>
        </div>

        <div class="randee-marketplace-stats">
            <div class="randee-marketplace-stat">
                <div class="randee-marketplace-stat__label">Статус связи</div>
                <div class="randee-marketplace-stat__value"><?= randee_update_marketplace_badge($connectionLabel, $connectionTone) ?></div>
                <div class="randee-marketplace-stat__hint">Сервер: <?= randee_update_marketplace_value((string)$settings['server_url']) ?></div>
            </div>
            <div class="randee-marketplace-stat">
                <div class="randee-marketplace-stat__label">Лицензия</div>
                <div class="randee-marketplace-stat__value"><?= randee_update_marketplace_badge($licenseReady ? 'Готова' : 'Не указана', $licenseTone) ?></div>
                <div class="randee-marketplace-stat__hint">Site UID: <?= randee_update_marketplace_value((string)$settings['site_uid']) ?></div>
            </div>
            <div class="randee-marketplace-stat">
                <div class="randee-marketplace-stat__label">Доступные продукты</div>
                <div class="randee-marketplace-stat__value"><?= randee_update_marketplace_badge((string)$ownedCount, $catalogTone) ?></div>
                <div class="randee-marketplace-stat__hint"><?= $lockedCount > 0 ? 'Ещё ' . $lockedCount . ' доступны после активации' : 'Все продукты доступны по лицензии' ?></div>
            </div>
            <div class="randee-marketplace-stat">
                <div class="randee-marketplace-stat__label">Последняя установка</div>
                <div class="randee-marketplace-stat__value"><?= randee_update_marketplace_badge($installLabel, $installTone) ?></div>
                <div class="randee-marketplace-stat__hint">Последняя синхронизация: <?= randee_update_marketplace_time($lastSyncLabel) ?></div>
            </div>
        </div>

        <?php if (isset($_GET['saved']) && $_GET['saved'] === 'Y'): ?>
            <div class="randee-marketplace-alert" style="margin-bottom: 16px;">
                Настройки сохранены.
            </div>
        <?php endif; ?>

        <?php if (!$connectionReady): ?>
            <div class="randee-marketplace-alert randee-marketplace-alert--warning" style="margin-bottom: 16px;">
                До начала работы заполните адрес сервера, ключ лицензии и Site UID, затем активируйте лицензию.
            </div>
        <?php endif; ?>

        <div class="randee-marketplace-layout">
            <div class="randee-marketplace-stack">
                <section class="randee-marketplace-panel" style="<?= $connectionReady && !$settingsEditMode ? 'display:none;' : '' ?>">
                    <div class="randee-marketplace-panel__header">
                        <div>
                            <h2 class="randee-marketplace-panel__title">Подключение и лицензия</h2>
                            <p class="randee-marketplace-panel__subtitle">Сначала сохраните настройки, потом активируйте лицензию и обновите каталог.</p>
                        </div>
                        <div><?= randee_update_marketplace_badge('Шаг 1', 'info') ?></div>
                    </div>

                    <form method="post" action="">
                        <?= bitrix_sessid_post() ?>
                        <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
                        <div class="randee-marketplace-grid-2">
                            <div class="randee-marketplace-field">
                                <label for="server_url">Адрес сервера обновлений</label>
                                <input id="server_url" type="text" name="server_url" value="<?= htmlspecialcharsbx((string)$settings['server_url']) ?>" class="adm-input" style="width: 100%;">
                                <div class="randee-marketplace-field__hint">Обычно это <code>https://updates.c0l.ru</code>.</div>
                            </div>
                            <div class="randee-marketplace-field">
                                <label for="license_key">Ключ лицензии</label>
                                <input id="license_key" type="text" name="license_key" value="<?= htmlspecialcharsbx((string)$settings['license_key']) ?>" class="adm-input" style="width: 100%;">
                                <div class="randee-marketplace-field__hint">Нужен для активации и доступа к каталогу.</div>
                            </div>
                            <div class="randee-marketplace-field">
                                <label for="site_uid">UID сайта</label>
                                <input id="site_uid" type="text" name="site_uid" value="<?= htmlspecialcharsbx((string)$settings['site_uid']) ?>" class="adm-input" style="width: 100%;">
                                <div class="randee-marketplace-field__hint">Позволяет различать сайты клиента.</div>
                            </div>
                            <div class="randee-marketplace-field">
                                <label for="channel">Канал обновлений</label>
                                <select id="channel" name="channel" class="adm-input" style="width: 100%;">
                                    <?php foreach (['stable', 'beta', 'hotfix', 'dev'] as $channel): ?>
                                        <option value="<?= htmlspecialcharsbx($channel) ?>"<?= ((string)$settings['channel'] === $channel ? ' selected' : '') ?>><?= htmlspecialcharsbx($channel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="randee-marketplace-field__hint">Канал определяет, какие релизы видит сайт.</div>
                            </div>
                            <div class="randee-marketplace-field">
                                <label for="temp_dir">Временный каталог</label>
                                <input id="temp_dir" type="text" name="temp_dir" value="<?= htmlspecialcharsbx((string)($settings['temp_dir'] ?? '')) ?>" class="adm-input" style="width: 100%;" placeholder="Автовыбор, если оставить пустым">
                                <div class="randee-marketplace-field__hint">Можно оставить пустым. Модуль попробует writable-каталоги автоматически: <code>bitrix/tmp</code>, <code>upload</code>, системный temp.</div>
                            </div>
                        </div>

                        <div class="randee-marketplace-grid-2" style="margin-top: 12px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                                <input type="checkbox" name="show_beta" value="Y"<?= $settings['show_beta'] ? ' checked' : '' ?>>
                                Показывать beta-релизы
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                                <input type="checkbox" name="enable_rollback" value="Y"<?= $settings['enable_rollback'] ? ' checked' : '' ?>>
                                Включить rollback
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                                <input type="checkbox" name="auto_check_updates" value="Y"<?= $settings['auto_check_updates'] ? ' checked' : '' ?>>
                                Автопроверка обновлений
                            </label>
                        </div>

                        <div class="randee-marketplace-actions">
                            <button type="submit" name="save_settings" value="Y" class="randee-marketplace-btn randee-marketplace-btn--primary">Сохранить настройки</button>
                            <button type="submit" name="activate_license" value="Y" class="randee-marketplace-btn">Активировать лицензию</button>
                        </div>
                    </form>

                    <div class="randee-marketplace-alert" style="margin-top: 16px;">
                        После активации страница подтянет каталог из сервиса и покажет только те продукты, которые доступны вашей лицензии.
                    </div>
                </section>

                <section class="randee-marketplace-panel">
                    <div class="randee-marketplace-panel__header">
                        <div>
                            <h2 class="randee-marketplace-panel__title">Каталог продуктов</h2>
                            <p class="randee-marketplace-panel__subtitle">Сначала показываем ваши продукты, отдельно — то, что ещё недоступно по лицензии.</p>
                        </div>
                        <div><?= randee_update_marketplace_badge($catalogCount . ' всего', 'neutral') ?></div>
                    </div>

                    <?php if ($catalogError !== ''): ?>
                        <div class="randee-marketplace-alert randee-marketplace-alert--danger" style="margin-bottom: 12px;">
                            <?= htmlspecialcharsbx($catalogError) ?>
                        </div>
                    <?php endif; ?>

                    <div class="randee-marketplace-field" style="margin-bottom: 12px;">
                        <label for="product_search">Быстрый поиск</label>
                        <input id="product_search" type="text" class="adm-input randee-marketplace-search" placeholder="Введите название или product_id">
                        <div class="randee-marketplace-field__hint">Поиск фильтрует оба списка прямо на странице.</div>
                    </div>

                    <h3 class="randee-marketplace-section-title">Ваши продукты</h3>
                    <div class="randee-marketplace-table-wrap" style="margin-bottom: 16px;">
                        <table class="randee-marketplace-table" data-randee-table="owned">
                            <thead>
                                <tr>
                                    <th>Продукт</th>
                                    <th>Тип</th>
                                    <th>Версия</th>
                                    <th>Доступ</th>
                                    <th>Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ownedProducts as $product): ?>
                                    <?php
                                    $productId = (string)($product['product_id'] ?? '');
                                    $productName = (string)($product['name'] ?? $productId);
                                    $productKind = randee_update_marketplace_product_type_label((array)$product);
                                    $latest = is_array($product['latest_release'] ?? null) ? $product['latest_release'] : null;
                                    $latestVersion = (string)($latest['version'] ?? '-');
                                    $latestChannel = (string)($latest['channel'] ?? '-');
                                    $installState = randee_update_marketplace_resolve_install_state((array)$product, $installedProducts, $lastInstallState, [
                                        'installed_version' => $installedVersion,
                                        'installed_release_tag' => $installedReleaseTag,
                                        'installed_build_number' => $installedBuildNumber,
                                        'installed_at' => $installedAt,
                                    ]);
                                    $rowInstalled = (bool)($installState['installed'] ?? false);
                                    $rowInstalledVersion = trim((string)($installState['version'] ?? ''));
                                    if ($rowInstalledVersion === '') {
                                        $rowInstalledVersion = $latestVersion !== '-' ? $latestVersion : '';
                                    }
                                    $rowUpdateAvailable = $rowInstalled && $rowInstalledVersion !== '' && $latestVersion !== '-' && VersionComparator::isUpdateAvailable($rowInstalledVersion, $latestVersion);
                                    $isSelected = $productId !== '' && $productId === $selectedProductId;
                                    $searchText = mb_strtolower($productName . ' ' . $productId . ' ' . $productKind . ' ' . $latestVersion . ' ' . $rowInstalledVersion);
                                    ?>
                                    <tr class="<?= $isSelected ? 'is-selected' : '' ?>" data-randee-product-row data-search="<?= htmlspecialcharsbx($searchText) ?>">
                                        <td>
                                            <strong><?= htmlspecialcharsbx($productName) ?></strong>
                                            <div class="randee-marketplace-table__muted"><?= htmlspecialcharsbx($productId) ?></div>
                                        </td>
                                        <td><?= htmlspecialcharsbx($productKind) ?></td>
                                        <td>
                                            <?php if ($rowInstalledVersion !== ''): ?>
                                                <div><?= htmlspecialcharsbx($rowInstalledVersion) ?></div>
                                                <div class="randee-marketplace-table__muted">Канал <?= htmlspecialcharsbx($latestChannel) ?></div>
                                                <?php if ($rowUpdateAvailable && $latestVersion !== ''): ?>
                                                    <div class="randee-marketplace-table__muted" style="color: #b42318;">Доступно обновление до <?= htmlspecialcharsbx($latestVersion) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?= htmlspecialcharsbx($latestVersion) ?>
                                                <div class="randee-marketplace-table__muted">Канал <?= htmlspecialcharsbx($latestChannel) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($rowUpdateAvailable): ?>
                                                <?= randee_update_marketplace_badge('Обновить', 'danger') ?>
                                            <?php elseif ($rowInstalled): ?>
                                                <?= randee_update_marketplace_badge('Установлено', 'success') ?>
                                            <?php else: ?>
                                                <?= randee_update_marketplace_badge('Доступ открыт', 'success') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('product=' . urlencode($productId), ['saved', 'lang'])) ?>">Открыть</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($ownedProducts === []): ?>
                                    <tr><td colspan="5"><div class="randee-marketplace-empty">Пока нет доступных продуктов. Проверьте лицензию или активируйте её.</div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="randee-marketplace-section-title">Доступно после покупки</h3>
                    <div class="randee-marketplace-table-wrap">
                        <table class="randee-marketplace-table" data-randee-table="locked">
                            <thead>
                                <tr>
                                    <th>Продукт</th>
                                    <th>Тип</th>
                                    <th>Версия</th>
                                    <th>Статус</th>
                                    <th>Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lockedProducts as $product): ?>
                                    <?php
                                    $productId = (string)($product['product_id'] ?? '');
                                    $productName = (string)($product['name'] ?? $productId);
                                    $productKind = randee_update_marketplace_product_type_label((array)$product);
                                    $latest = is_array($product['latest_release'] ?? null) ? $product['latest_release'] : null;
                                    $latestVersion = (string)($latest['version'] ?? '-');
                                    $latestChannel = (string)($latest['channel'] ?? '-');
                                    $searchText = mb_strtolower($productName . ' ' . $productId . ' ' . $productKind . ' ' . $latestVersion);
                                    ?>
                                    <tr data-randee-product-row data-search="<?= htmlspecialcharsbx($searchText) ?>">
                                        <td>
                                            <strong><?= htmlspecialcharsbx($productName) ?></strong>
                                            <div class="randee-marketplace-table__muted"><?= htmlspecialcharsbx($productId) ?></div>
                                        </td>
                                        <td><?= htmlspecialcharsbx($productKind) ?></td>
                                        <td>
                                            <?= htmlspecialcharsbx($latestVersion) ?>
                                            <div class="randee-marketplace-table__muted">Канал <?= htmlspecialcharsbx($latestChannel) ?></div>
                                        </td>
                                        <td><?= randee_update_marketplace_badge('Нужна лицензия', 'warning') ?></td>
                                        <td>
                                            <a href="<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('product=' . urlencode($productId), ['saved', 'lang'])) ?>">Открыть</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($lockedProducts === []): ?>
                                    <tr><td colspan="5"><div class="randee-marketplace-empty">Нет закрытых продуктов. Лицензия уже открывает весь каталог.</div></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>

            <aside class="randee-marketplace-stack">
                <section class="randee-marketplace-panel">
                    <div class="randee-marketplace-panel__header">
                        <div>
                            <h2 class="randee-marketplace-panel__title">Как работать</h2>
                            <p class="randee-marketplace-panel__subtitle">Минимальный сценарий без лишних решений и без риска запутаться.</p>
                        </div>
                        <div><?= randee_update_marketplace_badge('4 шага', 'info') ?></div>
                    </div>
                    <div class="randee-marketplace-help">
                        <div class="randee-marketplace-help__item">
                            <div class="randee-marketplace-help__index">1</div>
                            <div>
                                <p class="randee-marketplace-help__title">Сохраните подключение</p>
                                <p class="randee-marketplace-help__text">Проверьте адрес сервера, ключ лицензии, Site UID и канал обновлений.</p>
                            </div>
                        </div>
                        <div class="randee-marketplace-help__item">
                            <div class="randee-marketplace-help__index">2</div>
                            <div>
                                <p class="randee-marketplace-help__title">Активируйте лицензию</p>
                                <p class="randee-marketplace-help__text">После активации каталог подтянется из marketplace и станет понятнее, что доступно.</p>
                            </div>
                        </div>
                        <div class="randee-marketplace-help__item">
                            <div class="randee-marketplace-help__index">3</div>
                            <div>
                                <p class="randee-marketplace-help__title">Откройте продукт</p>
                                <p class="randee-marketplace-help__text">Выберите нужный модуль, компонент или шаблон и посмотрите его текущую версию.</p>
                            </div>
                        </div>
                        <div class="randee-marketplace-help__item">
                            <div class="randee-marketplace-help__index">4</div>
                            <div>
                                <p class="randee-marketplace-help__title">Скачайте или установите</p>
                                <p class="randee-marketplace-help__text">Сначала можно скачать пакет, затем установить. Если что-то пошло не так, используйте rollback.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="randee-marketplace-panel">
                    <div class="randee-marketplace-panel__header">
                        <div>
                            <h2 class="randee-marketplace-panel__title">Последние действия</h2>
                            <p class="randee-marketplace-panel__subtitle">Короткий статус без лишнего JSON. Все важное уже показано в карточках.</p>
                        </div>
                        <div><?= randee_update_marketplace_badge('Журнал', 'neutral') ?></div>
                    </div>

                    <div class="randee-marketplace-compact-list">
                        <div class="randee-marketplace-compact-item">
                            <div class="randee-marketplace-compact-item__left">
                                <p class="randee-marketplace-compact-item__title">Активация лицензии</p>
                                <p class="randee-marketplace-compact-item__text"><?= !empty($activationResult) ? 'Ответ получен от сервера.' : 'Пока нет ответа.' ?></p>
                            </div>
                            <div><?= randee_update_marketplace_badge(!empty($activationResult) ? 'Есть данные' : 'Нет данных', !empty($activationResult) ? 'success' : 'neutral') ?></div>
                        </div>
                        <div class="randee-marketplace-compact-item">
                            <div class="randee-marketplace-compact-item__left">
                                <p class="randee-marketplace-compact-item__title">Последняя загрузка</p>
                                <p class="randee-marketplace-compact-item__text"><?= $lastPackageFile !== '' ? 'Файл: ' . htmlspecialcharsbx(basename($lastPackageFile)) : 'Пакет ещё не скачан.' ?></p>
                            </div>
                            <div><?= randee_update_marketplace_badge($lastPackageFile !== '' ? 'Готово' : 'Пусто', $lastPackageFile !== '' ? 'info' : 'neutral') ?></div>
                        </div>
                        <div class="randee-marketplace-compact-item">
                            <div class="randee-marketplace-compact-item__left">
                                <p class="randee-marketplace-compact-item__title">Последняя операция</p>
                                <p class="randee-marketplace-compact-item__text"><?= !empty($lastOperation['message']) ? htmlspecialcharsbx((string)$lastOperation['message']) : 'Нет записей.' ?></p>
                            </div>
                            <div><?= randee_update_marketplace_badge(!empty($lastOperation) ? 'Есть запись' : 'Нет записи', !empty($lastOperation) ? 'success' : 'neutral') ?></div>
                        </div>
                    </div>
                </section>

                <section class="randee-marketplace-panel">
                    <div class="randee-marketplace-panel__header">
                        <div>
                            <h2 class="randee-marketplace-panel__title">Текущее состояние</h2>
                            <p class="randee-marketplace-panel__subtitle">Здесь видны локальные значения, которые помогут понять, что уже установлено.</p>
                        </div>
                    </div>

                    <div class="randee-marketplace-compact-list">
                        <div class="randee-marketplace-kv">
                            <div class="randee-marketplace-kv__label">Установленная версия</div>
                            <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value($installedVersion) ?></div>
                        </div>
                        <div class="randee-marketplace-kv">
                            <div class="randee-marketplace-kv__label">Release tag</div>
                            <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value($installedReleaseTag) ?></div>
                        </div>
                        <div class="randee-marketplace-kv">
                            <div class="randee-marketplace-kv__label">Build number</div>
                            <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value($installedBuildNumber) ?></div>
                        </div>
                        <div class="randee-marketplace-kv">
                            <div class="randee-marketplace-kv__label">Rollback</div>
                            <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_badge($settings['enable_rollback'] ? 'Включен' : 'Выключен', $settings['enable_rollback'] ? 'success' : 'warning') ?></div>
                        </div>
                        <div class="randee-marketplace-kv">
                            <div class="randee-marketplace-kv__label">Автопроверка</div>
                            <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_badge($settings['auto_check_updates'] ? 'Включена' : 'Выключена', $settings['auto_check_updates'] ? 'success' : 'neutral') ?></div>
                        </div>
                    </div>
                </section>

            </aside>
        </div>
    </div>
</div>
<?php if ($settingsEditMode): ?>
    <div class="randee-marketplace-settings-modal is-open" data-randee-settings-modal>
        <div class="randee-marketplace-settings-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="randee-marketplace-settings-modal-title">
            <div class="randee-marketplace-settings-modal__head">
                <div>
                    <h2 class="randee-marketplace-panel__title" id="randee-marketplace-settings-modal-title">Подключение и лицензия</h2>
                    <p class="randee-marketplace-panel__subtitle">Редактирование настроек, лицензии и канала обновлений.</p>
                </div>
                <a class="randee-marketplace-settings-modal__close" href="<?= htmlspecialcharsbx($settingsCloseUrl) ?>" data-randee-settings-modal-close aria-label="Закрыть">×</a>
            </div>

            <form method="post" action="">
                <?= bitrix_sessid_post() ?>
                <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
                <div class="randee-marketplace-grid-2">
                    <div class="randee-marketplace-field">
                        <label for="settings_server_url">Адрес сервера обновлений</label>
                        <input id="settings_server_url" type="text" name="server_url" value="<?= htmlspecialcharsbx((string)$settings['server_url']) ?>" class="adm-input" style="width: 100%;">
                        <div class="randee-marketplace-field__hint">Обычно это <code>https://updates.c0l.ru</code>.</div>
                    </div>
                    <div class="randee-marketplace-field">
                        <label for="settings_license_key">Ключ лицензии</label>
                        <input id="settings_license_key" type="text" name="license_key" value="<?= htmlspecialcharsbx((string)$settings['license_key']) ?>" class="adm-input" style="width: 100%;">
                        <div class="randee-marketplace-field__hint">Нужен для активации и доступа к каталогу.</div>
                    </div>
                    <div class="randee-marketplace-field">
                        <label for="settings_site_uid">UID сайта</label>
                        <input id="settings_site_uid" type="text" name="site_uid" value="<?= htmlspecialcharsbx((string)$settings['site_uid']) ?>" class="adm-input" style="width: 100%;">
                        <div class="randee-marketplace-field__hint">Позволяет различать сайты клиента.</div>
                    </div>
                    <div class="randee-marketplace-field">
                        <label for="settings_channel">Канал обновлений</label>
                        <select id="settings_channel" name="channel" class="adm-input" style="width: 100%;">
                            <?php foreach (['stable', 'beta', 'hotfix', 'dev'] as $channel): ?>
                                <option value="<?= htmlspecialcharsbx($channel) ?>"<?= ((string)$settings['channel'] === $channel ? ' selected' : '') ?>><?= htmlspecialcharsbx($channel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="randee-marketplace-field__hint">Канал определяет, какие релизы видит сайт.</div>
                    </div>
                    <div class="randee-marketplace-field">
                        <label for="settings_temp_dir">Временный каталог</label>
                        <input id="settings_temp_dir" type="text" name="temp_dir" value="<?= htmlspecialcharsbx((string)($settings['temp_dir'] ?? '')) ?>" class="adm-input" style="width: 100%;" placeholder="Автовыбор, если оставить пустым">
                        <div class="randee-marketplace-field__hint">Оставьте пустым, чтобы модуль сам выбрал writable-каталог. Можно указать свой путь, если нужно.</div>
                    </div>
                </div>

                <div class="randee-marketplace-grid-2" style="margin-top: 12px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <input type="checkbox" name="show_beta" value="Y"<?= $settings['show_beta'] ? ' checked' : '' ?>>
                        Показывать beta-релизы
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <input type="checkbox" name="enable_rollback" value="Y"<?= $settings['enable_rollback'] ? ' checked' : '' ?>>
                        Включить rollback
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
                        <input type="checkbox" name="auto_check_updates" value="Y"<?= $settings['auto_check_updates'] ? ' checked' : '' ?>>
                        Автопроверка обновлений
                    </label>
                </div>

                <div class="randee-marketplace-actions">
                    <button type="submit" name="save_settings" value="Y" class="randee-marketplace-btn randee-marketplace-btn--primary">Сохранить настройки</button>
                    <button type="submit" name="activate_license" value="Y" class="randee-marketplace-btn">Активировать лицензию</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php if ($openedProductId !== '' && $selectedProduct !== null): ?>
    <div class="randee-marketplace-product-modal is-open" data-randee-product-modal>
        <div class="randee-marketplace-product-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="randee-marketplace-product-modal-title">
            <div class="randee-marketplace-product-modal__head">
                <div>
                    <h2 class="randee-marketplace-panel__title" id="randee-marketplace-product-modal-title">Выбранный продукт</h2>
                    <p class="randee-marketplace-panel__subtitle">Сначала обложка и описание продукта, потом технические данные и действия.</p>
                </div>
                <a class="randee-marketplace-product-modal__close" href="<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('', ['product', 'saved', 'lang'])) ?>" data-randee-product-modal-close aria-label="Закрыть">×</a>
            </div>

            <div class="randee-marketplace-product-hero">
                <div class="randee-marketplace-product-hero__media">
                    <?php if ($selectedProductPageCoverUrl !== ''): ?>
                        <img src="<?= htmlspecialcharsbx($selectedProductPageCoverUrl) ?>" alt="<?= htmlspecialcharsbx($selectedProductPageTitle !== '' ? $selectedProductPageTitle : $selectedProductName) ?>">
                    <?php else: ?>
                        <div class="randee-marketplace-product-hero__placeholder">Изображение продукта появится здесь. Можно использовать первую картинку из галереи или отдельную обложку.</div>
                    <?php endif; ?>
                </div>
                <div class="randee-marketplace-product-hero__body">
                    <div class="randee-marketplace-product-hero__eyebrow">Страница продукта</div>
                    <h3 class="randee-marketplace-product-hero__title"><?= htmlspecialcharsbx($selectedProductPageTitle !== '' ? $selectedProductPageTitle : $selectedProductName) ?></h3>
                    <?php if ($selectedProductPageDescription !== ''): ?>
                        <div class="randee-marketplace-product-hero__text"><?= htmlspecialcharsbx($selectedProductPageDescription) ?></div>
                    <?php else: ?>
                        <div class="randee-marketplace-product-hero__text">Описание продукта можно редактировать главному администратору прямо в карточке продукта.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="randee-marketplace-product-grid" style="margin-top: 12px;">
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Название</div>
                    <div class="randee-marketplace-kv__value"><?= htmlspecialcharsbx($selectedProductName) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Product ID</div>
                    <div class="randee-marketplace-kv__value"><?= htmlspecialcharsbx($selectedProductId) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Тип</div>
                    <div class="randee-marketplace-kv__value"><?= htmlspecialcharsbx($selectedProductKind !== '' ? $selectedProductKind : '—') ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Доступ</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_badge($selectedAccessLabel, $selectedAccessTone) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Текущая версия</div>
                    <div class="randee-marketplace-kv__value">
                        <?php if ($selectedInstalledVersion !== ''): ?>
                            <div><?= htmlspecialcharsbx($selectedInstalledVersion) ?></div>
                        <?php elseif ($installedVersion !== ''): ?>
                            <div><?= htmlspecialcharsbx($installedVersion) ?></div>
                        <?php else: ?>
                            <div>—</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Новая версия</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_badge($selectedReleaseLabel, $selectedUpdateAvailable ? 'danger' : 'success') ?></div>
                </div>
            </div>

            <div class="randee-marketplace-product-grid" style="margin-top: 12px;">
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Release tag</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value((string)($selectedRelease['release_tag'] ?? '')) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Build number</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value((string)($selectedRelease['build_number'] ?? '')) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">SHA256</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value((string)($selectedRelease['sha256'] ?? '')) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Установлено</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_value($selectedInstalledAt) ?></div>
                </div>
                <div class="randee-marketplace-kv">
                    <div class="randee-marketplace-kv__label">Статус установки</div>
                    <div class="randee-marketplace-kv__value"><?= randee_update_marketplace_badge($selectedInstalled ? 'Установлено' : 'Не установлено', $selectedInstalled ? 'info' : 'neutral') ?></div>
                </div>
            </div>

            <?php if ($selectedProductPageInstallation !== '' || $selectedProductPageSettings !== '' || $selectedProductPageVideoUrl !== ''): ?>
                <div class="page-rich-preview" style="margin-top: 12px;">
                    <?php if ($selectedProductPageInstallation !== ''): ?>
                        <div class="page-rich-preview__block">
                            <h4>Установка</h4>
                            <p><?= htmlspecialcharsbx($selectedProductPageInstallation) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($selectedProductPageSettings !== ''): ?>
                        <div class="page-rich-preview__block">
                            <h4>Настройки</h4>
                            <p><?= htmlspecialcharsbx($selectedProductPageSettings) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($selectedProductPageVideoUrl !== ''): ?>
                        <div class="page-rich-preview__block">
                            <h4>Видео</h4>
                            <p><?= htmlspecialcharsbx($selectedProductPageVideoUrl) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($selectedProductPageGallery !== []): ?>
                <div class="page-media-grid" style="margin-top: 12px;">
                    <?php foreach (array_slice($selectedProductPageGallery, 0, 6) as $galleryUrl): ?>
                        <div class="page-media-card">
                            <img src="<?= htmlspecialcharsbx($galleryUrl) ?>" alt="">
                            <div class="page-media-card__label"><?= htmlspecialcharsbx($galleryUrl) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$selectedProductAllowed): ?>
                <div class="randee-marketplace-alert randee-marketplace-alert--warning" style="margin-top: 14px;">
                    Этот продукт пока недоступен по текущей лицензии. Сначала активируйте или обновите лицензию.
                </div>
            <?php elseif ($selectedUpdateAvailable): ?>
                <div class="randee-marketplace-alert" style="margin-top: 14px;">
                    Доступно обновление с версии <?= randee_update_marketplace_value($selectedInstalledVersion !== '' ? $selectedInstalledVersion : $installedVersion) ?> до <?= randee_update_marketplace_value($selectedReleaseVersion) ?>.
                </div>
            <?php endif; ?>

            <div class="randee-marketplace-actions">
                <form method="post" action="" style="display: inline-block; margin: 0;" data-randee-action-form>
                    <?= bitrix_sessid_post() ?>
                    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
                    <input type="hidden" name="product" value="<?= htmlspecialcharsbx($selectedProductId) ?>">
                    <button type="submit" name="download_latest" value="Y" class="randee-marketplace-btn" data-loading-title="Скачиваем пакет" data-loading-text="Подождите, архив обновления загружается на сервер."<?= !$selectedProductAllowed ? ' disabled' : '' ?>>Скачать пакет</button>
                </form>
                <form method="post" action="" style="display: inline-block; margin: 0;" data-randee-action-form>
                    <?= bitrix_sessid_post() ?>
                    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
                    <input type="hidden" name="product" value="<?= htmlspecialcharsbx($selectedProductId) ?>">
                    <button type="submit" name="install_latest" value="Y" class="randee-marketplace-btn randee-marketplace-btn--primary" data-loading-title="Устанавливаем обновление" data-loading-text="Пакет скачивается, проверяется и распаковывается.">Установить</button>
                </form>
                <form method="post" action="" style="display: inline-block; margin: 0;" data-randee-action-form>
                    <?= bitrix_sessid_post() ?>
                    <input type="hidden" name="lang" value="<?= htmlspecialcharsbx(LANGUAGE_ID) ?>">
                    <input type="hidden" name="product" value="<?= htmlspecialcharsbx($selectedProductId) ?>">
                    <button type="submit" name="rollback_latest" value="Y" class="randee-marketplace-btn randee-marketplace-btn--danger" data-loading-title="Выполняем откат" data-loading-text="Возвращаем предыдущую версию и восстанавливаем файлы."<?= (!$settings['enable_rollback'] || $lastInstallState === []) ? ' disabled' : '' ?>>Откатить</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
$flashType = (string)($flashNotice['type'] ?? 'neutral');
$flashTitle = (string)($flashNotice['title'] ?? '');
$flashMessage = (string)($flashNotice['message'] ?? '');
$flashReload = !empty($flashNotice['details']['reload']);
$flashIcon = match ($flashType) {
    'success' => '✓',
    'danger' => '!',
    'warning' => '!',
    default => 'i',
};
$noticeClasses = 'randee-marketplace-notice';
if ($flashTitle !== '' || $flashMessage !== '') {
    $noticeClasses .= ' is-open';
}
?>
<div
    class="<?= htmlspecialcharsbx($noticeClasses) ?>"
    data-randee-notice-modal
    data-randee-notice-type="<?= htmlspecialcharsbx($flashType) ?>"
    data-randee-notice-title="<?= htmlspecialcharsbx($flashTitle) ?>"
    data-randee-notice-message="<?= htmlspecialcharsbx($flashMessage) ?>"
    data-randee-notice-reload="<?= $flashReload ? 'Y' : 'N' ?>"
>
    <div class="randee-marketplace-notice__dialog" role="dialog" aria-modal="true" aria-labelledby="randee-marketplace-notice-title">
        <div class="randee-marketplace-notice__head">
            <div style="display: flex; gap: 14px; align-items: flex-start;">
                <div class="randee-marketplace-notice__icon randee-marketplace-notice__icon--<?= htmlspecialcharsbx($flashType) ?>" data-randee-notice-icon><?= htmlspecialcharsbx($flashIcon) ?></div>
                <div>
                    <h3 class="randee-marketplace-notice__title" id="randee-marketplace-notice-title" data-randee-notice-title-text><?= htmlspecialcharsbx($flashTitle !== '' ? $flashTitle : 'Готово') ?></h3>
                    <p class="randee-marketplace-notice__text" data-randee-notice-message-text><?= htmlspecialcharsbx($flashMessage !== '' ? $flashMessage : 'Операция завершена.') ?></p>
                </div>
            </div>
            <button type="button" class="randee-marketplace-settings-modal__close" data-randee-notice-modal-close aria-label="Закрыть">×</button>
        </div>
        <div class="randee-marketplace-notice__actions">
            <button type="button" class="randee-marketplace-btn randee-marketplace-btn--primary" data-randee-notice-ok><?= $flashReload ? 'Обновить страницу' : 'Понятно' ?></button>
        </div>
    </div>
</div>

<div class="randee-marketplace-loading" data-randee-loading>
    <div class="randee-marketplace-loading__dialog" role="dialog" aria-modal="true" aria-labelledby="randee-marketplace-loading-title">
        <div class="randee-marketplace-loading__head">
            <div style="display: flex; gap: 14px; align-items: flex-start;">
                <div class="randee-marketplace-loading__icon"></div>
                <div>
                    <h3 class="randee-marketplace-loading__title" id="randee-marketplace-loading-title">Выполняем операцию</h3>
                    <p class="randee-marketplace-loading__text" data-randee-loading-text>Подождите, не закрывайте страницу.</p>
                </div>
            </div>
        </div>
        <div class="randee-marketplace-loading__progress"><span></span></div>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('product_search');
    var rows = document.querySelectorAll('[data-randee-product-row]');
    var supportsAjax = !!(window.fetch && window.FormData);
    function filterRows() {
        if (!input) {
            return;
        }
        var query = input.value.toLowerCase().trim();
        rows.forEach(function (row) {
            var haystack = (row.getAttribute('data-search') || '').toLowerCase();
            row.style.display = !query || haystack.indexOf(query) !== -1 ? '' : 'none';
        });
    }

    if (input) {
        input.addEventListener('input', filterRows);
    }

    var modal = document.querySelector('[data-randee-product-modal]');
    var settingsModal = document.querySelector('[data-randee-settings-modal]');
    var noticeModal = document.querySelector('[data-randee-notice-modal]');
    var loadingOverlay = document.querySelector('[data-randee-loading]');
    var noticeTitleNode = noticeModal ? noticeModal.querySelector('[data-randee-notice-title-text]') : null;
    var noticeMessageNode = noticeModal ? noticeModal.querySelector('[data-randee-notice-message-text]') : null;
    var noticeIconNode = noticeModal ? noticeModal.querySelector('[data-randee-notice-icon]') : null;
    var noticeOkButton = noticeModal ? noticeModal.querySelector('[data-randee-notice-ok]') : null;

    function syncBodyLock() {
        var hasOpen = false;
        if (modal && modal.classList.contains('is-open')) {
            hasOpen = true;
        }
        if (settingsModal && settingsModal.classList.contains('is-open')) {
            hasOpen = true;
        }
        if (noticeModal && noticeModal.classList.contains('is-open')) {
            hasOpen = true;
        }
        if (loadingOverlay && loadingOverlay.classList.contains('is-open')) {
            hasOpen = true;
        }
        document.body.classList.toggle('randee-marketplace-modal-open', hasOpen);
    }

    function openLoading(title, text) {
        if (!loadingOverlay) {
            return;
        }
        var loadingTitle = loadingOverlay.querySelector('[id="randee-marketplace-loading-title"]');
        var loadingText = loadingOverlay.querySelector('[data-randee-loading-text]');
        if (loadingTitle) {
            loadingTitle.textContent = title || 'Выполняем операцию';
        }
        if (loadingText) {
            loadingText.textContent = text || 'Подождите, не закрывайте страницу.';
        }
        loadingOverlay.classList.add('is-open');
        syncBodyLock();
    }

    function closeLoading() {
        if (!loadingOverlay) {
            return;
        }
        loadingOverlay.classList.remove('is-open');
        syncBodyLock();
    }

    function openNotice(payload) {
        if (!noticeModal) {
            return;
        }
        var type = payload && payload.type ? payload.type : 'neutral';
        var title = payload && payload.title ? payload.title : 'Готово';
        var message = payload && payload.message ? payload.message : 'Операция завершена.';
        var icon = type === 'success' ? '✓' : (type === 'danger' || type === 'warning' ? '!' : 'i');
        var reload = !!(payload && payload.reload);

        noticeModal.dataset.randeeNoticeType = type;
        noticeModal.dataset.randeeNoticeReload = reload ? 'Y' : 'N';
        noticeModal.className = 'randee-marketplace-notice is-open';
        noticeModal.classList.add('is-open');

        if (noticeTitleNode) {
            noticeTitleNode.textContent = title;
        }
        if (noticeMessageNode) {
            noticeMessageNode.textContent = message;
        }
        if (noticeIconNode) {
            noticeIconNode.textContent = icon;
            noticeIconNode.className = 'randee-marketplace-notice__icon randee-marketplace-notice__icon--' + type;
        }
        if (noticeOkButton) {
            noticeOkButton.textContent = reload ? 'Обновить страницу' : 'Понятно';
        }

        syncBodyLock();
    }

    function closeNotice() {
        if (!noticeModal) {
            return;
        }
        noticeModal.classList.remove('is-open');
        syncBodyLock();
    }

    if (modal && modal.classList.contains('is-open')) {
        document.body.classList.add('randee-marketplace-modal-open');
    } else if (settingsModal && settingsModal.classList.contains('is-open')) {
        document.body.classList.add('randee-marketplace-modal-open');
    } else if (noticeModal && noticeModal.classList.contains('is-open')) {
        document.body.classList.add('randee-marketplace-modal-open');
    }

    document.querySelectorAll('[data-randee-action-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || document.activeElement;
            if (!submitter || submitter.disabled) {
                return;
            }
            if (!supportsAjax) {
                return;
            }
            event.preventDefault();
            openLoading(
                submitter.getAttribute('data-loading-title') || 'Выполняем операцию',
                submitter.getAttribute('data-loading-text') || 'Подождите, не закрывайте страницу.'
            );
            form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                button.disabled = true;
            });

            var formData = new FormData(form);
            formData.set('randee_ajax', 'Y');
            if (submitter.name) {
                formData.set(submitter.name, submitter.value || 'Y');
            }
            fetch(form.action || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = {
                            success: false,
                            type: 'danger',
                            title: 'Ошибка',
                            message: 'Сервер вернул неожиданный ответ: ' + text.slice(0, 400)
                        };
                    }
                    if (!response.ok && !data.title) {
                        data = {
                            success: false,
                            type: 'danger',
                            title: 'Ошибка',
                            message: 'Запрос завершился с ошибкой ' + response.status + '.'
                        };
                    }
                    return data;
                });
            }).then(function (data) {
                closeLoading();
                openNotice(data || {});
                if (data && data.reload) {
                    noticeOkButton && noticeOkButton.setAttribute('data-randee-reload', 'Y');
                }
            }).catch(function (error) {
                closeLoading();
                openNotice({
                    success: false,
                    type: 'danger',
                    title: 'Ошибка',
                    message: error && error.message ? error.message : 'Не удалось выполнить запрос.'
                });
            }).finally(function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.disabled = false;
                });
            });
        });
    });

    document.addEventListener('click', function (event) {
        if (modal && (event.target === modal || event.target.closest('[data-randee-product-modal-close]'))) {
            window.location.href = '<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('', ['product', 'saved', 'lang'])) ?>';
            return;
        }
        if (settingsModal && (event.target === settingsModal || event.target.closest('[data-randee-settings-modal-close]'))) {
            window.location.href = '<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('', ['edit_settings', 'saved', 'lang'])) ?>';
            return;
        }
        if (noticeModal && event.target && event.target.closest('[data-randee-notice-ok]')) {
            if (noticeModal.dataset.randeeNoticeReload === 'Y') {
                window.location.reload();
                return;
            }
            closeNotice();
            return;
        }
        if (noticeModal && (event.target === noticeModal || event.target.closest('[data-randee-notice-modal-close]'))) {
            closeNotice();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal) {
            window.location.href = '<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('', ['product', 'saved', 'lang'])) ?>';
            return;
        }
        if (event.key === 'Escape' && settingsModal) {
            window.location.href = '<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam('', ['edit_settings', 'saved', 'lang'])) ?>';
            return;
        }
        if (event.key === 'Escape' && noticeModal) {
            closeNotice();
        }
    });
})();
</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
