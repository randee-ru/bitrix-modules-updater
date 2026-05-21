<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('randee.update', [
    'Randee\\Update\\LicenseManager' => 'lib/LicenseManager.php',
    'Randee\\Update\\Manifest' => 'lib/Manifest.php',
    'Randee\\Update\\MarketplaceClient' => 'lib/MarketplaceClient.php',
    'Randee\\Update\\PackageDownloader' => 'lib/PackageDownloader.php',
    'Randee\\Update\\PackageInstaller' => 'lib/PackageInstaller.php',
    'Randee\\Update\\PackageValidator' => 'lib/PackageValidator.php',
    'Randee\\Update\\ProductRegistry' => 'lib/ProductRegistry.php',
    'Randee\\Update\\ReleaseRegistry' => 'lib/ReleaseRegistry.php',
    'Randee\\Update\\RollbackManager' => 'lib/RollbackManager.php',
    'Randee\\Update\\UpdateLogger' => 'lib/UpdateLogger.php',
    'Randee\\Update\\VersionComparator' => 'lib/VersionComparator.php',
]);
