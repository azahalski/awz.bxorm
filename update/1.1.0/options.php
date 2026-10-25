<?require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Awz\BxOrm\Helper;
Loc::loadMessages(__FILE__);
global $APPLICATION;
$module_id = "awz.bxorm";
$MODULE_RIGHT = $APPLICATION->GetGroupRight($module_id);
$zr = "";
if (! ($MODULE_RIGHT >= "R"))
$APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));

$APPLICATION->SetTitle(Loc::getMessage('AWZ_BXORM_OPT_TITLE'));

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

Loader::includeModule($module_id);

$mcpMessage = null;
if ($MODULE_RIGHT == "W" && check_bitrix_sessid())
{
    if(strlen($_REQUEST["MCP_CREATE"])>0){
        $resMcp = Helper::createMcpApp();
        if($resMcp->isSuccess()){
            $data = $resMcp->getData();
            $mcpMessage = new CAdminMessage(array(
                "MESSAGE" => Loc::getMessage('AWZ_BXORM_OPT_MCP_CREATED_OK'),
                "TYPE" => "OK",
                "HTML" => true,
                "DETAILS" =>
                    Loc::getMessage('AWZ_BXORM_OPT_MCP_URL').':<br><a href="'.htmlspecialcharsbx($data['URL']).'" target="_blank">'.htmlspecialcharsbx($data['URL']).'</a><br><br>'.
                    Loc::getMessage('AWZ_BXORM_OPT_MCP_TOKEN').': <b>'.htmlspecialcharsbx($data['TOKEN']).'</b><br><br>'.
                    Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_DESC').':<br>'.
                    Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_QUERY').' <code>'.htmlspecialcharsbx($data['URL']).'&key='.htmlspecialcharsbx($data['TOKEN']).'</code><br>'.
                    Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_HEADER').': <code>Authorization: Token '.htmlspecialcharsbx($data['TOKEN']).'</code>'
            ));
        }else{
            $mcpMessage = new CAdminMessage(array(
                "MESSAGE" => Loc::getMessage('AWZ_BXORM_OPT_MCP_ERR'),
                "TYPE" => "ERROR",
                "DETAILS" => htmlspecialcharsbx(implode('; ', array_map(fn($e) => $e->getMessage(), $resMcp->getErrors())))
            ));
        }
    }elseif(strlen($_REQUEST["MCP_DELETE"])>0){
        $resMcp = Helper::deleteMcpApp();
        if($resMcp->isSuccess()){
            $mcpMessage = new CAdminMessage(array(
                "MESSAGE" => Loc::getMessage('AWZ_BXORM_OPT_MCP_DELETED_OK'),
                "TYPE" => "OK"
            ));
        }else{
            $mcpMessage = new CAdminMessage(array(
                "MESSAGE" => Loc::getMessage('AWZ_BXORM_OPT_MCP_ERR'),
                "TYPE" => "ERROR",
                "DETAILS" => htmlspecialcharsbx(implode('; ', array_map(fn($e) => $e->getMessage(), $resMcp->getErrors())))
            ));
        }
    }
}
if($mcpMessage){
    echo $mcpMessage->Show();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $MODULE_RIGHT == "W" && strlen($_REQUEST["Update"]) > 0 && check_bitrix_sessid())
{
    Option::set($module_id, "MCP_SERVER_VERSION", trim((string)$_REQUEST["MCP_SERVER_VERSION"]));
    Option::set($module_id, "MCP_PROTO_VERSION", trim((string)$_REQUEST["MCP_PROTO_VERSION"]));
}

$aTabs = array();

$aTabs[] = array(
    "DIV" => "edit1",
    "TAB" => Loc::getMessage('AWZ_BXORM_OPT_SECT1'),
    "ICON" => "vote_settings",
    "TITLE" => Loc::getMessage('AWZ_BXORM_OPT_SECT1')
);

$aTabs[] = array(
    "DIV" => "edit3",
    "TAB" => Loc::getMessage('AWZ_BXORM_OPT_SECT3'),
    "ICON" => "vote_settings",
    "TITLE" => Loc::getMessage('AWZ_BXORM_OPT_SECT3')
);

$tabControl = new CAdminTabControl("tabControl", $aTabs);
$tabControl->Begin();
?>
    <style>.adm-workarea option:checked {background-color: rgb(206, 206, 206);}</style>
    <form method="POST" action="<?echo $APPLICATION->GetCurPage()?>?mid=<?=htmlspecialcharsbx($module_id)?>&lang=<?=LANGUAGE_ID?>&mid_menu=1" id="FORMACTION">

        <?
        $tabControl->BeginNextTab();
        $mcpHook = Helper::getMcpHook();
        $mcpMethodId = Helper::getMcpMethodId();
        $mcpUrl = '';
        if($mcpHook){
            $mcpUrl = Helper::getMcpUrl((int)$mcpHook['ID']);
        }
        $mcpBtnParams = ' class="adm-btn adm-btn-green" style="margin-right:10px;"';
        ?>
        <tr>
            <td colspan="2">
                <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_VERSIONS')?>: <b>\Awz\BxOrm\Api\Controller\Mcp\App::$MCP_SERVER_VERSION</b> = <?=\Awz\BxOrm\Api\Controller\Mcp\App::$MCP_SERVER_VERSION?>, <b>\Awz\BxOrm\Api\Controller\Mcp\App::$MCP_PROTO_VERSION</b> = <?=\Awz\BxOrm\Api\Controller\Mcp\App::$MCP_PROTO_VERSION?><br><br>
                <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_DESC')?><br><br>
                <table>
                    <tr>
                        <td style="padding-right:15px;"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_SERVER_VERSION')?></td>
                        <td style="padding-right:30px;"><input type="text" name="MCP_SERVER_VERSION" size="10" value="<?=htmlspecialcharsbx(\Awz\BxOrm\Api\Controller\Mcp\App::getMcpServerVersion())?>"></td>
                        <td style="padding-right:15px;"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_PROTO_VERSION')?></td>
                        <td><input type="text" name="MCP_PROTO_VERSION" size="14" value="<?=htmlspecialcharsbx(\Awz\BxOrm\Api\Controller\Mcp\App::getProtoVersion())?>"></td>
                    </tr>
                    <tr>
                        <td colspan="4" style="padding-top:3px;color:#666;"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_VERSIONS_HINT')?></td>
                    </tr>
                </table>
                <br>
                <?if($mcpHook && $mcpMethodId){?>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_URL')?>: <a href="<?=htmlspecialcharsbx($mcpUrl)?>" target="_blank"><?=htmlspecialcharsbx($mcpUrl)?></a><br>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_TOKEN')?>: <b><?=htmlspecialcharsbx($mcpHook['TOKEN'])?></b><br>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_DESC')?><br>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_QUERY')?>: <code><?=htmlspecialcharsbx($mcpUrl)?>&key=<?=htmlspecialcharsbx($mcpHook['TOKEN'])?></code><br>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_AUTH_HEADER')?>: <code>Authorization: Token <?=htmlspecialcharsbx($mcpHook['TOKEN'])?></code>
                    <br><br>
                <?}else{?>
                    <?=Loc::getMessage('AWZ_BXORM_OPT_MCP_NOT_CREATED')?><br><br>
                <?}?>
                <a href="<?=$APPLICATION->GetCurPage()?>?mid=<?=htmlspecialcharsbx($module_id)?>&lang=<?=LANGUAGE_ID?>&mid_menu=1&MCP_CREATE=Y&sessid=<?=bitrix_sessid()?>"<?=$mcpBtnParams?> onclick="return confirm('<?=CUtil::JSEscape(Loc::getMessage('AWZ_BXORM_OPT_MCP_CREATE_CONFIRM'))?>');"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_CREATE')?></a>
                <?if($mcpHook && $mcpMethodId){?>
                    <a href="<?=$APPLICATION->GetCurPage()?>?mid=<?=htmlspecialcharsbx($module_id)?>&lang=<?=LANGUAGE_ID?>&mid_menu=1&MCP_DELETE=Y&sessid=<?=bitrix_sessid()?>" class="adm-btn" onclick="return confirm('<?=CUtil::JSEscape(Loc::getMessage('AWZ_BXORM_OPT_MCP_DELETE_CONFIRM'))?>');"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_DELETE')?></a>
                <?}?>
                <br><br>
                <a href="/bitrix/admin/awz_bxorm_methods_list.php?lang=<?=LANGUAGE_ID?>" class="adm-btn" style="margin-right:10px;"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_LINK_METHODS')?></a>
                <a href="/bitrix/admin/awz_bxorm_hooks_list.php?lang=<?=LANGUAGE_ID?>" class="adm-btn"><?=Loc::getMessage('AWZ_BXORM_OPT_MCP_LINK_HOOKS')?></a>
            </td>
        </tr>

        <?
        $tabControl->BeginNextTab();
        require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/admin/group_rights.php");
        ?>

        <?
        $tabControl->Buttons();
        ?>
        <input <?if ($MODULE_RIGHT<"W") echo "disabled" ?> type="submit" class="adm-btn-green" name="Update" value="<?=Loc::getMessage('AWZ_BXORM_OPT_L_BTN_SAVE')?>" />
        <input type="hidden" name="Update" value="Y" />
        <?$tabControl->End();?>
    </form>
<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");