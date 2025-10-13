<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}
$dirs = explode(DIRECTORY_SEPARATOR, dirname(__DIR__, 1));
$MODULE_ID = array_pop($dirs);
unset($dirs);
$optFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR. 'options.php';
if(file_exists($optFilePath)){
    LocalRedirect('/bitrix/admin/settings.php?lang='.LANG.'&mid='.$MODULE_ID);
}else{
    LocalRedirect('/bitrix/admin/partner_modules.php?lang='.LANG);
}