<?php

namespace Awz\BxOrm\AdminPages;

use Bitrix\Main\Localization\Loc;
use Awz\Admin\IList;
use Awz\Admin\IParams;
use Awz\Admin\Helper;
use Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class HooksList extends IList implements IParams {

    public function __construct($params){
        parent::__construct($params);
    }

    public function trigerGetRowListAdmin($row){

        Helper::viewListField($row, 'ID', ['type'=>'entity_link'], $this);
        Helper::viewListField($row, 'NAME', ['type'=>'entity_link'], $this);
        Helper::editListField($row, 'NAME', ['type'=>'string'], $this);
        Helper::editListField($row, 'ACTIVE', ['type'=>'checkbox'], $this);

        $domain = Application::getInstance()->getContext()->getServer()->getHttpHost();
        $baseApiUrl = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.app.call&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'].'&method=';
        $baseApiUrlHook = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.call&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'].'&method=';
        $baseApiUrl2 = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.app.methods&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'];
        $baseApiUrlHook2 = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.methods&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'];
        $baseApiUrlMcp = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.bxmcp.callrpc&app='.$row->arRes['ID'].'&mcprpc=2.0';

        $urlLink = static function(string $url, string $label): string {
            return '<a href="'.htmlspecialcharsbx($url).'" target="_blank" title="'.htmlspecialcharsbx($url).'">'.htmlspecialcharsbx($label).'</a>';
        };
        $methodChip = static function(string $url, string $code): string {
            return '<span style="display:inline-block;margin:1px 3px 1px 0;padding:1px 7px;background:#eaf3fb;border:1px solid #c9ddf0;border-radius:10px;font-size:11px;white-space:nowrap;">'
                .'<a href="'.htmlspecialcharsbx($url).'" target="_blank" title="'.htmlspecialcharsbx($url).'">'.htmlspecialcharsbx($code).'</a>'
                .'</span>';
        };

        $methodsOrm = [];
        $methodsMcp = [];
        $methodsApp = [];
        if(!empty($row->arRes['METHODS'])){
            $methodsRes = \Awz\BxOrm\MethodsTable::getList([
                'select'=>['*'],
                'filter'=>['=ID'=>$row->arRes['METHODS'], '=ACTIVE'=>'Y']
            ]);
            while($data = $methodsRes->fetch()){
                foreach($data['PARAMS']['methods'] as $k=>$v){
                    if($v !== 'Y') continue;
                    $methodCode = $data['CODE'].'.'.$k;
                    if(
                        (class_exists($data['ENTITY']) && method_exists($data['ENTITY'], 'getEntity'))
                    || (mb_strpos($data['CODE'], 'orm'))
                    ){
                        //ORM-сущность — стандартные методы с ограничениями (hook)
                        $methodsOrm[] = $methodChip($baseApiUrlHook.$methodCode, $methodCode);
                    }elseif(mb_strpos(mb_strtolower((string)$data['ENTITY']), 'mcp\\app') !== false
                        || mb_strpos($data['CODE'], 'mcp') === 0){
                        //MCP-сущность (или код mcp) — только MCP-транспорт
                        $methodsMcp[] = $methodChip($baseApiUrlMcp, $methodCode);
                    }else{
                        //контроллер — вызов с любыми параметрами (app)
                        $methodsApp[] = $methodChip($baseApiUrl.$methodCode, $methodCode);
                    }
                }
            }
        }

        $html = '<div style="font-size:12px;line-height:1.9;">';

        if(!empty($methodsMcp)){
            $html .= '<div><b style="color:#2a6496;">MCP</b> (JSON-RPC 2.0): '.$urlLink($baseApiUrlMcp, 'bxmcp.callrpc').'</div>';
            $html .= '<div style="margin-top:4px;"><b style="color:#7a4fbf;">'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_MCP_SHORT').'</b> <span style="color:#888;">('.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_MCP_HINT').')</span>:<br>'.implode('', $methodsMcp).'</div>';
        }
        if(!empty($methodsOrm)){
            $html .= '<div><b>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK').'</b> (hook.call — orm параметры): '.$urlLink($baseApiUrlHook, 'app='.$row->arRes['ID']).'</div>';
            $html .= '<div><b>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK_2').':</b> '.$urlLink($baseApiUrlHook2, 'hook.methods').'</div>';
            $html .= '<div style="margin-top:4px;"><b style="color:#4f7d2c;">'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_ORM_SHORT').'</b> <span style="color:#888;">('.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_ORM_HINT').')</span>:<br>'.implode('', $methodsOrm).'</div>';
        }
        if(!empty($methodsApp)){
            $html .= '<div><b>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK').'</b> (app.call — любые параметры): '.$urlLink($baseApiUrl, 'app='.$row->arRes['ID']).'</div>';
            $html .= '<div><b>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK_2').':</b> '.$urlLink($baseApiUrl2, 'hook.methods').'</div>';
            $html .= '<div style="margin-top:4px;"><b style="color:#2a6496;">'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_APP_SHORT').'</b> <span style="color:#888;">('.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_METHOD_APP_HINT').')</span>:<br>'.implode('', $methodsApp).'</div>';
        }
        $html .= '</div>';

        $row->AddViewField('METHODS', $html);

    }

    public function trigerInitFilter(){
    }

    public function trigerGetRowListActions(array $actions): array
    {
        return $actions;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_BXORM_HOOKS_LIST_TITLE');
    }

    public static function getParams(): array
    {
        $arParams = array(
            "ENTITY" => "\\Awz\\BxOrm\\HooksTable",
            "FILE_EDIT" => "awz_bxorm_hooks_edit.php",
            "BUTTON_CONTEXTS"=>array('btn_new'=>array(
                'TEXT'=>Loc::getMessage('AWZ_BXORM_HOOKS_LIST_ADD_BTN'),
                'ICON'	=> 'btn_new',
                'LINK'	=> 'awz_bxorm_hooks_edit.php?lang='.LANG
            )),
            "ADD_GROUP_ACTIONS"=>array("edit","delete"),
            "ADD_LIST_ACTIONS"=>array("delete","edit"),
            "FIND"=>[],
            "FIND_FROM_ENTITY"=>['ID'=>[],'NAME'=>[],'TOKEN'=>[],'ACTIVE'=>[]]
        );
        return $arParams;
    }
}