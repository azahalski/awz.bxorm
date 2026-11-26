<?php
/**
 * @var CUpdater $updater
 */
$moduleId = 'awz.bxorm';
// Проверяем, существует ли папка с js в самом обновлении
if(\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId)){
    if (is_dir(__DIR__ . '/install/js'))
    {
        $updater->CopyFiles("install/js", "bitrix/js/".$moduleId);
    }
    if (is_dir(__DIR__ . '/install/css'))
    {
        $updater->CopyFiles("install/css", "bitrix/css/".$moduleId);
    }
    if (is_dir(__DIR__ . '/install/admin'))
    {
        $updater->CopyFiles("install/admin", "bitrix/admin");
    }
}
