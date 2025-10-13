<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}
/**
 * @global CMain $APPLICATION
 */

$dirs = explode(DIRECTORY_SEPARATOR, dirname(__DIR__, 1));
$moduleId = array_pop($dirs);
unset($dirs);
$opts = ['base'=>true, 'sett'=>true, 'mail'=>false];
IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/'.$moduleId.'/install/install.php");
?>
<style>
    .awz_module-wrap-btn {display:block;width:100%;padding-top:20px;clear:both;overflow:hidden;}
    .awz_module-wrap-opts {display:block;width:100%;margin-top:10px;clear:both;overflow:hidden;}
    .awz_module-wrap-opts-row {background: #ffffff;padding:20px;margin:10px 20px 10px 0;
        width: 400px;float:left;border-radius: 5px;}
</style>
<form action="<?= $APPLICATION->GetCurPage()?>">
	<?=bitrix_sessid_post()?>
    <input type="hidden" name="lang" value="<?=LANG?>">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="id" value="<?=$moduleId?>">
    <input type="hidden" name="step" value="2">
    <?CAdminMessage::ShowMessage(GetMessage('MOD_UNINST_WARN'))?>
    <div class="awz_module-wrap-opts">
        <?if($opts['base']){?>
        <div class="awz_module-wrap-opts-row">
            <p><b><?= GetMessage('MOD_UNINST_SAVE')?></b></p>
            <p>
                <input type="checkbox" name="save" id="save" value="Y" checked>
                <label for="save"><?= GetMessage('MOD_UNINST_SAVE_TABLES')?></label>
            </p>
        </div>
        <?}?>
        <?if($opts['sett']){?>
        <div class="awz_module-wrap-opts-row">
            <p><b><?= GetMessage('MOD_UNINST_SAVE_OPTS')?>:</b></p>
            <p>
                <input type="checkbox" name="saveopts" id="saveopts" value="Y" checked>
                <label for="saveopts"><?= GetMessage('MOD_UNINST_SAVE_OPTS_LABEL')?></label>
            </p>
        </div>
        <?}?>
        <?if($opts['mail']){?>
        <div class="awz_module-wrap-opts-row">
            <p><b><?= GetMessage('MOD_UNINST_SAVE_EVENTS_DESC')?>:</b></p>
            <p>
                <input type="checkbox" name="mail" id="mail" value="Y" checked>
                <label for="mail"><?= GetMessage('MOD_UNINST_SAVE_EVENTS')?></label>
            </p>
        </div>
        <?}?>
    </div>
    <div class="awz_module-wrap-btn">
    <input type="submit" value="<?= GetMessage('MOD_UNINST_DEL')?>">
    </div>
</form>