<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
Loc::loadMessages(__FILE__);

global $APPLICATION;
$POST_RIGHT = $APPLICATION->GetGroupRight("awz.bxorm");
if ($POST_RIGHT == "D") return;

if(Loader::includeModule('awz.bxorm')){
    $aMenu[] = array(
        "parent_menu" => "global_menu_services",
        "section" => "awz_bxorm",
        "sort" => 100,
        "module_id" => "awz.bxorm",
        "text" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_TITLE'),
        "title" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_TITLE'),
        "items_id" => "awz_bxapi",
        "items" => array(
            array(
                "text" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_HOOK'),
                "url" => "awz_bxorm_hooks_list.php?lang=".LANGUAGE_ID,
                "more_url" => Array("awz_bxorm_hooks_edit.php?lang=".LANGUAGE_ID),
                "title" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_HOOK'),
                "sort" => 100,
            ),
            array(
                "text" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_METHODS'),
                "url" => "awz_bxorm_methods_list.php?lang=".LANGUAGE_ID,
                "more_url" => Array("awz_bxorm_methods_edit.php?lang=".LANGUAGE_ID),
                "title" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_METHODS'),
                "sort" => 110,
            ),
            array(
                "text" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_OPTIONS'),
                "url" => "/bitrix/admin/settings.php?lang=".LANGUAGE_ID."&mid=awz.bxorm&mid_menu=1",
                "title" => Loc::getMessage('AWZ_BXORM_ADMIN_MENU_OPTIONS'),
                "sort" => 120,
            )
        ),
    );
    return $aMenu;
}