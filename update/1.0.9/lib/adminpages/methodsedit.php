<?php

namespace Awz\BxOrm\AdminPages;

use Awz\Admin\Helper;
use Awz\BxOrm\Helper as MainHelper;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IForm;
use Awz\Admin\IParams;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Awz\BxOrm\MethodsTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Result;
use Bitrix\Main\Error;

Loc::loadMessages(__FILE__);

class MethodsEdit extends IForm implements IParams {

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
        return Loc::getMessage('AWZ_BXORM_METHODS_EDIT_TITLE');
    }

    public function getFieldValue($name){

        $value = "";
        $defValue = isset($this->fieldsValues[$name]) ? $this->fieldsValues[$name] : "";

        if(!$this->saved) {
            $value = (isset($_REQUEST[$name]) && array_key_exists($name, $_REQUEST)) ? \htmlspecialcharsEx($_REQUEST[$name]) : $defValue;
        }else{
            $value = (isset($this->fieldsValues[$name])) ? $this->fieldsValues[$name] : "";
        }

        if(!$value && $name==='FIELD_TOKEN'){
            $value = MainHelper::generateToken();
        }

        if(!$value && $name==='FIELD_MODULES'){
            return [];
        }
        if(!$value && $name==='FIELD_PARAMS'){
            return [];
        }
        return $value;
    }

    public function getElValue(){
        $id = $this->getParam("ID");
        $entity = $this->getParam("ENTITY");
        $arData = $entity::getRowById($id);
        $fields = $this->getParam("FIELDS");
        foreach($fields as $group=>$fl){
            foreach($fl as $field){
                $arField = array(
                    "ID" => $field["NAME"],
                    "NAME" => "FIELD_".$field["NAME"],
                );
                $this->fieldsValues[$arField["NAME"]] = $arData[$arField["ID"]];
            }
        }
        //print_r($this->fieldsValues);
        //die();
        if(!is_array($this->fieldsValues["FIELD_MODULES"])){
            $this->fieldsValues["FIELD_MODULES"] = [];
        }
        if(!is_array($this->fieldsValues["FIELD_PARAMS"])){
            $this->fieldsValues["FIELD_PARAMS"] = [];
        }
    }

    public static function checkCorrectField(Fields\Field $field): Result
    {
        $resultCheck = new Result();
        if($field instanceof Fields\IntegerField){
            return $resultCheck;
        }
        if($field instanceof Fields\StringField){
            return $resultCheck;
        }
        if($field instanceof Fields\DatetimeField){
            return $resultCheck;
        }
        if($field instanceof Fields\DateField){
            return $resultCheck;
        }
        if($field instanceof Fields\FloatField){
            return $resultCheck;
        }
        if($field instanceof Fields\EnumField){
            return $resultCheck;
        }
        if($field instanceof Fields\ExpressionField){
            $valueType = $field->getValueField();
            if($valueType instanceof Fields\StringField){
                return $resultCheck;
            }
            if($field->getParameter('data_type')==='string'){
                return $resultCheck;
            }
        }
        if($field instanceof Fields\BooleanField){
            $values = $field->getValues();
            if(is_array($values) && isset($values[0],$values[1]) && $values[0]=='N' && $values[1] == 'Y'){
                return $resultCheck;
            }else{
                $resultCheck->addError(new Error(Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ERR_YN')));
                return $resultCheck;
            }
        }
        $resultCheck->addError(new Error(Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ERR2')));
        return $resultCheck;
    }
    public function paramsFieldViewIsRequired($field, $arField, $lv1 = null, $lv2 = null){
        $fieldCode = $arField['NAME'].'[fields]['.$field->getName().']';
        $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$field->getName()];
        if($lv1){
            $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$field->getName().']';
            $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$field->getName()];
            if($lv2){
                $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$lv2->getName().'.'.$field->getName().']';
                $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()];
            }
        }
        $readOnly = false;
        if($field->getParameter('required')) {
            if(!isset($fieldVal['isRequired'])){
                $fieldVal['isRequired'] = ($field->getParameter('required')) ? "Y" : "N";
            }
        }
        if($field->getParameter('primary')) $readOnly = true;
        ?>
        <?if($readOnly){?>
            <input type="checkbox" name="<?=$fieldCode?>[isRequired_]" value="Y" checked="checked" disabled="disabled" readonly="readonly"> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_IS_REQ')?>
            <input type="hidden" name="<?=$fieldCode?>[isRequired]" value="Y">
        <?}else{?>
            <input type="checkbox" name="<?=$fieldCode?>[isRequired]" value="Y"<?=($fieldVal['isRequired']=='Y' ? ' checked="checked"' : '')?>> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_IS_REQ')?>
        <?}?>

        <?php
    }
    public function paramsFieldViewReadonly($field, $arField, $lv1 = null, $lv2 = null){
        $fieldCode = $arField['NAME'].'[fields]['.$field->getName().']';
        $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$field->getName()];
        if($lv1){
            $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$field->getName().']';
            $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$field->getName()];
            if($lv2){
                $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$lv2->getName().'.'.$field->getName().']';
                $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()];
            }
        }
        $readOnly = false;
        if($field->getParameter('isReadonly')) $readOnly = true;
        if($field->getParameter('readonly')) $readOnly = true;
        if($field->getParameter('primary')) $readOnly = true;
        if($field instanceof Fields\ExpressionField){
            $readOnly = true;
        }
        ?>
        <?if($readOnly){?>
            <input type="checkbox" name="<?=$fieldCode?>[isReadonly_]" value="Y" checked="checked" disabled="disabled" readonly="readonly"> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ONLY_READ')?>
            <input type="hidden" name="<?=$fieldCode?>[isReadonly]" value="Y">
        <?}else{?>
            <input type="checkbox" name="<?=$fieldCode?>[isReadonly]" value="Y"<?=($fieldVal['isReadonly']=='Y' ? ' checked="checked"' : '')?>> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ONLY_READ')?>
        <?}?>

        <?php
    }
    public function paramsFieldViewActive($field, $arField, $lv1 = null, $lv2 = null){
        $fieldCode = $arField['NAME'].'[fields]['.$field->getName().']';
        $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$field->getName()];
        if($lv1){
            $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$field->getName().']';
            $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$field->getName()];
            if($lv2){
                $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$lv2->getName().'.'.$field->getName().']';
                $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()];
            }
        }
        $readOnly = false;
        //if($field->getParameter('private')) $readOnly = true;
        //if($field->getParameter('primary')) $readOnly = true;
        ?>
        <?if($readOnly){?>
            <input type="checkbox" name="<?=$fieldCode?>[isActive]" value="Y" checked="checked" disabled="disabled" readonly="readonly"> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_ACTIVE_PRM')?>
        <?}else{?>
            <input type="checkbox" name="<?=$fieldCode?>[isActive]" value="Y"<?=($fieldVal['isActive']=='Y' ? ' checked="checked"' : '')?>> - <?if($fieldVal['isActive']=='Y'){?><span style="color:green;font-weight:bold;"><?}?><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_ACTIVE_PRM')?><?if($fieldVal['isActive']=='Y'){?></span><?}?>
        <?}?>

        <?php
    }
    public function paramsFieldViewIsSortable($field, $arField, $lv1 = null, $lv2 = null){
        $fieldCode = $arField['NAME'].'[fields]['.$field->getName().']';
        $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$field->getName()];
        if($lv1){
            $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$field->getName().']';
            $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$field->getName()];
            if($lv2){
                $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$lv2->getName().'.'.$field->getName().']';
                $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()];
            }
        }
        $readOnly = false;
        //if($field->getParameter('private')) $readOnly = true;
        //if($field->getParameter('primary')) $readOnly = true;
        ?>
        <?if($readOnly){?>
            <input type="checkbox" name="<?=$fieldCode?>[isSortable]" value="Y" checked="checked" disabled="disabled" readonly="readonly"> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_SORTABLE_PRM')?>
        <?}else{?>
            <input type="checkbox" name="<?=$fieldCode?>[isSortable]" value="Y"<?=($fieldVal['isSortable']=='Y' ? ' checked="checked"' : '')?>> - <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_SORTABLE_PRM')?>
        <?}?>

        <?php
    }

    public static function getNumRow(){
        static $numRow = 0;
        $numRow++;
        return $numRow;
    }
    public function printFieldParams($field, $arField, $lv1 = null, $lv2 = null){
        $numRowCurrent = self::getNumRow();
        if($numRowCurrent>1000 && $lv2){
            return;
        }
        $r_style = 'style="width:25%;padding:5px;border-bottom:1px solid #87919c;border-right:1px solid #87919c;"';
        $fieldCode = $arField['NAME'].'[fields]['.$field->getName().']';
        $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$field->getName()];
        if($lv1){
            $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$field->getName().']';
            $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$field->getName()];
            if($lv2){
                $fieldCode = $arField['NAME'].'[fields]['.$lv1->getName().'.'.$lv2->getName().'.'.$field->getName().']';
                $fieldVal = $this->getFieldValue($arField['NAME'])['fields'][$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()];
            }
        }
        ?>
        <tr>
            <td><?=$numRowCurrent;?></td>
            <td <?=$r_style;?>>
                <?if($lv1 && !$lv2){?>
                    --1lv-- [<?=$lv1->getName().'.'.$field->getName()?>] - <?=$field->getTitle()?>
                <?}elseif($lv1 && $lv2){?>
                    --1lv-- --2lv-- [<?=$lv1->getName().'.'.$lv2->getName().'.'.$field->getName()?>] - <?=$field->getTitle()?>
                <?}else{?>
                    [<?=$field->getName()?>] - <?=$field->getTitle()?>
                <?}?>
            </td>
            <td style="width:75%;padding:5px;border-bottom:1px solid #87919c;">
                <b><?=get_class($field)?></b><br><br>
                <?

                $showDef = false;
                $checkResult = $this->checkCorrectField($field);
                if(!$checkResult->isSuccess()){
                    ?>
                    <p style="color:red;"><?=implode("<br>",$checkResult->getErrorMessages())?></p>
                    <?
                }elseif($field->getParameter('primary')===true){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>primary</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="primary">
                <?}elseif($field instanceof Fields\StringField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>string</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="string">
                <?}elseif($field instanceof Fields\IntegerField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>integer</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="integer">
                <?}elseif($field instanceof Fields\FloatField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>float</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="float">
                <?}elseif($field instanceof Fields\DateField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>date</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="date">
                <?}elseif($field instanceof Fields\DateTimeField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>date</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="datetime">
                <?}elseif($field instanceof Fields\EnumField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>enum</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="enum">
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_VALUES')?>:<br>
                    <?foreach($field->getValues() as $codeVal=>$rowVal){?>
                        <?=$codeVal?>: <?=$rowVal?>;
                    <?}?><br>
                <?}elseif($field instanceof Fields\BooleanField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>boolean</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="boolean">
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_VALUES')?>:<br>
                    <?foreach($field->getValues() as $codeVal=>$rowVal){?>
                        <?=$codeVal?>: <?=$rowVal?>;
                    <?}?><br>
                <?}elseif($field instanceof Fields\ExpressionField){
                    $showDef = true;
                    ?>
                    <?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_FIELDS_TYPE')?>:
                    <b>string</b><br>
                    <input type="hidden" name="<?=$fieldCode?>[type]" value="string">
                <?}?>
                <?if($showDef){?>
                    <?=$this->paramsFieldViewReadonly($field, $arField, $lv1, $lv2)?>
                    <?=$this->paramsFieldViewActive($field, $arField, $lv1, $lv2)?><br>
                    <?=$this->paramsFieldViewIsRequired($field, $arField, $lv1, $lv2)?>
                    <?=$this->paramsFieldViewIsSortable($field, $arField, $lv1, $lv2)?>
                    <br>
                    <?
                    //$fieldVal
                    $fileTitleDef = $field->getTitle() ? $field->getTitle() : $field->getName();
                    ?>
                    <input style="margin-top:5px;" type="text" name="<?=$fieldCode?>[title]" value="<?=$fieldVal['title'] ? $fieldVal['title'] : $fileTitleDef?>">
                <?}?>
            </td>
        </tr>
        <?php
    }

    public function paramsFieldView($arField){
        $r_style = 'style="width:25%;padding:5px;border-bottom:1px solid #87919c;border-right:1px solid #87919c;"';
        $valueField = $this->getFieldValue($arField['NAME']);
        $entity = $this->getFieldValue('FIELD_ENTITY');
        $entityCl = $this->getParam("ENTITY");
        $modules = $this->getFieldValue('FIELD_MODULES');
        if(is_array($modules)){
            $errModules = 'main';
            foreach ($modules as $module){
                if(!Loader::includeModule($module)){
                    $errModules = $module;
                    break;
                }else{
                    $errModules = '';
                }
            }
        }
        if($errModules){
            ?><p style="color:red;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ERR1')?> [<?=$errModules?>]</p><?php
        }
        ?>
        <?
        //\Bitrix\Main\Engine\Controller
        $methods = [];
        if($entity && is_string($entity) && class_exists($entity) && method_exists($entity, 'getEntity')){
            $methods = MainHelper::getMethods($entityCl::getEntity());
        }
        $controllerType = '';
        if($entity && is_string($entity) && class_exists($entity) && method_exists($entity, 'addScopeApi')){
            $methods = MainHelper::getMethods($entity);
            $controllerType = MainHelper::CONTROLLER_TYPE_AWZ;
        }elseif($entity && is_string($entity) && class_exists($entity) && method_exists($entity, 'listNameActions')){
            $methods = MainHelper::getMethods($entity);
            $controllerType = MainHelper::CONTROLLER_TYPE_BX;
        }
        //var_dump(class_exists($entity));

        ?>
        <h2><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM22')?></h2>
        <?if($controllerType === MainHelper::CONTROLLER_TYPE_BX){?>
            <div class="adm-info-message-wrap">
                <div class="adm-info-message">
                    <div> <?=Loc::getMessage('AWZ_BXORM_METHODS_BXAPI_MSG')?></div>
                </div>
            </div>
        <?}?>
        <table>
            <tr>
                <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;border-right:1px solid #87919c;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM22_3')?></th>
                <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;border-right:1px solid #87919c;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM22_1')?></th>
                <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM22_2')?></th>
            </tr>
            <?foreach($methods as $code=>$name){?>
                <tr>
                    <td <?=$r_style?>>
                        <input type="checkbox" name="<?=$arField['NAME']?>[methods][<?=$code?>]" value="Y"<?=($valueField['methods'][$code]=='Y' ? ' checked="checked"' : '')?>>
                    </td>
                    <td <?=$r_style?>><?=$code?></td>
                    <td style="width:50%;padding:5px;border-bottom:1px solid #87919c;">
                        <?=$name?>
                    </td>
                </tr>
            <?}?>
        </table>
        <?
        if($controllerType){
            $event = new Event(
                "awz.bxorm",
                "showField",
                ['entity'=>$entity]
            );
            $event->send();
            if ($event->getResults()) {
                foreach ($event->getResults() as $evenResult) {
                    if ($evenResult->getType() == EventResult::SUCCESS) {
                        $r = $evenResult->getParameters();
                        if(isset($r['func']) && is_array($r['func'])){
                            call_user_func_array($r['func'], [$this, $arField]);
                        }
                    }
                }
            }
            if(method_exists($entity, 'showBxOrmParams')){
                $entity::showBxOrmParams();
            }
        }else{
            ?>

            <?php
            if(is_string($entity) && $entity && class_exists($entity) && method_exists($entity, 'getEntity')){
                ?>
                <h2><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM11')?></h2>
                <table>
                <tr>
                    <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;border-right:1px solid #87919c;">№</th>
                    <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;border-right:1px solid #87919c;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM11_1')?></th>
                    <th style="padding:5px;text-align:left;border-bottom:1px solid #87919c;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_PRM11_2')?></th>
                </tr>
                <?php
                $fields = $entity::getMap();
                /* @var $field \Bitrix\Main\ORM\Fields\Field */
                foreach($fields as $field){
                    if($field instanceof Fields\Relations\Reference){
                        if($field->getRefEntity()) {
                            foreach ($field->getRefEntity()->getFields() as $rel1Field) {
                                if($rel1Field instanceof Fields\Relations\Reference){
                                    if($rel1Field->getRefEntity()) {
                                        foreach ($rel1Field->getRefEntity()->getFields() as $rel2Field) {
                                            $rel2Field->setParameter('readonly', true);
                                            $this->printFieldParams($rel2Field, $arField, $field, $rel1Field);
                                        }
                                    }
                                }else{
                                    $rel1Field->setParameter('readonly', true);
                                    $this->printFieldParams($rel1Field, $arField, $field);
                                }
                            }
                        }
                    }else{
                        $this->printFieldParams($field, $arField);
                    }
                }?>
                </table>
                <?
                //echo'<pre>';print_r($fields);echo'</pre>';
            }elseif(is_string($entity) && $entity){
                if(!class_exists($entity)){
                    ?><p style="color:red;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ERR3')?> [<?=$entity?>]</p><?
                }else{
                    ?><p style="color:red;"><?=Loc::getMessage('AWZ_BXORM_METHODS_EDIT_ERR4')?> [<?=$entity?>]</p><?
                }
            }
            ?><?
        }
    }

    public static function getParams(): array
    {
        //print_r(\Awz\BxOrm\MethodsTable::getEntity()->getField("MODULES")->getTitle());
        //die();
        $modules = MainHelper::getModuleList();

        $arParams = array(
            "ENTITY" => "\\Awz\\BxOrm\\MethodsTable",
            "BUTTON_CONTEXTS"=>array('btn_list'=>false),
            "LIST_URL"=>'/bitrix/admin/awz_bxorm_methods_list.php',
            "TABS"=>array(
                "edit1" => array(
                    "NAME"=>Loc::getMessage('AWZ_BXORM_METHODS_EDIT_EDIT1'),
                    "FIELDS" => array(
                        "NAME",
                        "CODE",
                        "ENTITY",
                        "MODULES"=>[
                            "NAME"=>"MODULES",
                            "VALUES"=>$modules,
                            "TYPE"=>"SELECT",
                            "ADD_STR"=>'multiple="Y" size="10"',
                        ],
                        "ACTIVE",
                        "PARAMS"=>[
                            "TYPE"=>"CUSTOM",
                            "NAME"=>"PARAMS",
                            "FUNC_VIEW"=>"paramsFieldView"
                        ],
                    )
                )
            )
        );
        return $arParams;
    }
}