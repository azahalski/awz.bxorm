<?php

namespace Awz\BxOrm\AdminPages;

use Awz\Admin\Helper;
use Awz\BxOrm\Helper as MainHelper;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IForm;
use Awz\Admin\IParams;

Loc::loadMessages(__FILE__);

class HooksEdit extends IForm implements IParams {

    public function __construct($params){
        parent::__construct($params);
    }

    public function trigerCheckActionAdd($func){
        return $func;
    }

    public function trigerCheckActionUpdate($func){
        return $func;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_BXORM_HOOKS_EDIT_TITLE');
    }

    public function getFieldValue($name){

        $value = "";
        $defValue = isset($this->fieldsValues[$name]) ? $this->fieldsValues[$name] : "";

        if(!$this->saved) {
            $value = (isset($_REQUEST[$name]) && array_key_exists($name, $_REQUEST)) ? \htmlspecialcharsEx($_REQUEST[$name]) : $defValue;
        }else{
            $value = (isset($this->fieldsValues[$name])) ? $this->fieldsValues[$name] : "";
        }

        if(!is_array($value) && $name==='FIELD_METHODS'){
            return [];
        }
        return $value;
    }

    public static function getParams(): array
    {
        $methods = MainHelper::getMethodsList();
        $arParams = array(
            "ENTITY" => "\\Awz\\BxOrm\\HooksTable",
            "BUTTON_CONTEXTS"=>array('btn_list'=>false),
            "LIST_URL"=>'/bitrix/admin/awz_bxorm_hooks_list.php',
            "DEFAULT_VALUES"=>[
                "FIELD_TOKEN"=>MainHelper::generateToken(),
                "FIELD_ACTIVE"=>"Y"
            ],
            "TABS"=>array(
                "edit1" => array(
                    "NAME"=>Loc::getMessage('AWZ_BXORM_HOOKS_EDIT_EDIT1'),
                    "FIELDS" => array(
                        "NAME",
                        "TOKEN",
                        "ACTIVE",
                        "METHODS"=>[
                            "NAME"=>"METHODS",
                            "TYPE"=>"SELECT",
                            "ADD_STR"=>'multiple="Y" size="10"',
                            "VALUES"=>$methods
                        ],
                    )
                )
            )
        );
        return $arParams;
    }
}