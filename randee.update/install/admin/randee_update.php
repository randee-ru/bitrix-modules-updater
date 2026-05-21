<?php

use Bitrix\Main\Loader;

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

LocalRedirect('/bitrix/admin/randee_update_marketplace.php?lang=' . LANGUAGE_ID);
