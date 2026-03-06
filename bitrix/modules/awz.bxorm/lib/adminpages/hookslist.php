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
        $baseApiUrl = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.call&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'].'&method=';
        $baseApiUrl2 = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.methods&app='.$row->arRes['ID'].'&key='.$row->arRes['TOKEN'];
        $baseApiUrlMcp = 'https://'.$domain.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.callrpc&app='.$row->arRes['ID'].'&mcprpc=2.0';

        $methods = [];
        if(!empty($row->arRes['METHODS'])){
            $methodsRes = \Awz\BxOrm\MethodsTable::getList([
                'select'=>['*'],
                'filter'=>['=ID'=>$row->arRes['METHODS'], '=ACTIVE'=>'Y']
            ]);
            while($data = $methodsRes->fetch()){
                foreach($data['PARAMS']['methods'] as $k=>$v){
                    if($v === 'Y')
                        $methods[] = '<a href="'.$baseApiUrl.$data['CODE'].'.'.$k.'" target="_blank">'.$data['CODE'].'.'.$k.'</a>';
                }
            }
        }

        $row->AddViewField('METHODS','rpc: <a target="_blank" href="'.$baseApiUrlMcp.'">rpc 2.0</a><br>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK').': <a target="_blank" href="'.$baseApiUrl.'">'.$baseApiUrl.'</a><br>'.Loc::getMessage('AWZ_BXORM_HOOKS_LIST_WEBHOOK_2').': <a target="_blank" href="'.$baseApiUrl2.'">'.$baseApiUrl2.'</a><br>'.implode(', ',$methods));

        ///bitrix/services/main/ajax.php?action=awz:bxorm.api.hook.call&app=1&key=k3ZaH73tSLx9I6us8C&method=

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