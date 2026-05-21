<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class randee_update extends CModule
{
    public $MODULE_ID = 'randee.update';
    public $MODULE_VERSION = '';
    public $MODULE_VERSION_DATE = '';
    public $MODULE_NAME = '';
    public $MODULE_DESCRIPTION = '';
    public $PARTNER_NAME = '';
    public $PARTNER_URI = '';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '0.1.0');
        $this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');
        $this->MODULE_NAME = Loc::getMessage('RANDEE_UPDATE_MODULE_NAME') ?: 'Randee Update Marketplace';
        $this->MODULE_DESCRIPTION = Loc::getMessage('RANDEE_UPDATE_MODULE_DESC') ?: 'Marketplace and update service for Randee Bitrix products';
        $this->PARTNER_NAME = Loc::getMessage('RANDEE_UPDATE_PARTNER_NAME') ?: 'Randee';
        $this->PARTNER_URI = Loc::getMessage('RANDEE_UPDATE_PARTNER_URI') ?: 'https://randee.ru';
    }

    public function DoInstall(): bool
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallFiles();
        return true;
    }

    public function DoUninstall(): bool
    {
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }

    public function InstallFiles(): bool
    {
        CopyDirFiles(
            __DIR__ . '/admin',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin',
            true,
            true
        );

        return true;
    }

    public function UnInstallFiles(): bool
    {
        $base = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/bitrix/admin/';
        @unlink($base . 'randee_update.php');
        @unlink($base . 'randee_update_marketplace.php');

        return true;
    }
}
