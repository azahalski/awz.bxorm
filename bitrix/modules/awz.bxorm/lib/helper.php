<?php

namespace Awz\BxOrm;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class Helper {

    const MCP_PROTO_VERSION_DEFAULT = '2.0';

    const CONTROLLER_TYPE_AWZ = 'awz';
    const CONTROLLER_TYPE_BX = 'bitrix';

    public static function getMethodsList(){
        static $methodsList = [];
        if(empty($methodsList)){
            $r = MethodsTable::getList([
                'select'=>['ID','NAME','CODE','PARAMS'],
                'filter'=>['=ACTIVE'=>'Y']
            ]);
            while($data = $r->fetch()){
                $active = [];
                if(isset($data['PARAMS']['methods']) && is_array($data['PARAMS']['methods'])){
                    foreach($data['PARAMS']['methods'] as $code=>$isActive){
                        if($isActive === 'Y'){
                            $active[] = $code;
                        }
                    }
                }
                $methodsList[$data['ID']] = $data['CODE'].'.('.implode('|',$active).') - '.$data['NAME'];
                foreach($active as $code){
                    $methodsList[$data['ID'].'.'.$code] = ' - '.$data['CODE'].'.'.$code;
                }
            }
        }
        return $methodsList;
    }

    public static function getModuleList(){
        static $moduleList = [];
        if(empty($moduleList)){
            $moduleList["main"] = "main";
            $moduleDir = Application::getInstance()->getContext()->getServer()->getDocumentRoot().'/bitrix/modules/';
            foreach(glob($moduleDir.'*/') as $dir){
                $dirOb = new Directory($dir);
                if($dirOb->isDirectory()){
                    //print_r($dirOb->getPath());die();
                    $versionFile = new File($dirOb->getPath().'/install/version.php');
                    if($versionFile->isExists()){
                        $moduleId = str_replace($moduleDir, '', $dirOb->getPath());
                        $moduleList[$moduleId] = $moduleId;
                    }
                }
            }
        }
        return $moduleList;
    }

    public static function getMethods($entity){
        $methods = [];

        $event = new Event(
            "awz.bxorm",
            "onBeforeGetMethods",
            ['entity'=>$entity]
        );
        $event->send();
        if ($event->getResults()) {
            foreach ($event->getResults() as $evenResult) {
                if ($evenResult->getType() == EventResult::SUCCESS) {
                    $r = $evenResult->getParameters();
                    if(isset($r['methods']) && is_array($r['methods'])){
                        foreach($r['methods'] as $c=>$v){
                            $methods[$c] = $v;
                        }
                    }
                }
            }
        }

        if(is_string($entity) && class_exists($entity) && method_exists($entity, 'addScopeApi')){
            $controller = new $entity();
            $actions = $controller->listNameActions();
            foreach($actions as $code){
                $methods[$code] = $code.'Action';
            }
        }elseif(is_string($entity) && class_exists($entity) && method_exists($entity, 'listNameActions')){
            $controller = new $entity();
            $actions = $controller->listNameActions();
            foreach($actions as $code){
                $methods[$code] = $code.'Action';
            }
        }

        if($entity instanceof \Bitrix\Main\ORM\Entity){
            $codes = [
                'list','update','delete','add','fields','get'
            ];
            foreach($codes as $code){
                $lang = Loc::getMessage('AWZ_BXORM_HELPER_METHOD_'.mb_strtoupper($code));
                $methods[$code] = $lang ?? $code;
            }
        }
        return $methods;
    }

    public static function generateToken(): string
    {
        $token = Random::getStringByAlphabet(
            (int) Option::get("awz.bxorm", 'token_len', 28, ""),
            Random::ALPHABET_NUM|Random::ALPHABET_ALPHALOWER|Random::ALPHABET_ALPHAUPPER,
            true
        );
        return $token;
    }

    public static function checkServiceKey(int $appId, string $key): bool
    {
        if(!$key) return false;
        $r = HooksTable::getRowById($appId);
        if(!$r) return false;
        if($r['ACTIVE'] && $r['ACTIVE']!='Y') return false;
        if($r['TOKEN'] && ($r['TOKEN']===$key)) {
            return true;
        }
        return false;
    }

    public static function replaceMethod($method, $appId){

        static $replaceMethod;
        if(!is_array($replaceMethod)) $replaceMethod = [];
        if(!isset($replaceMethod[$method])){
            $replaceMethod[$method] = strtolower(preg_replace('/[^0-9a-zA-Z.\/]/is','',$method));
            if(strpos($method, '.')===false){
                $mcpData = \Awz\BxOrm\HooksTable::getRowById($appId);
                if($mcpData && !empty($mcpData['METHODS'])){
                    foreach($mcpData['METHODS'] as $v){
                        $v = strtolower($v);
                        $initFind = str_replace(['.initialize'],'',$v);
                        if($initFind == intval($initFind)){
                            $methodData = \Awz\BxOrm\MethodsTable::getRowById(
                                str_replace(['.initialize'],'',$v)
                            );
                            if($methodData && ($methodData['ACTIVE']=='Y')){
                                $replaceMethod[$method] = $methodData['CODE'].'.'.preg_replace('/[^0-9a-zA-Z.\/]/is','',$method);
                                break;
                            }
                        }

                    }
                }
            }
        }
        return $replaceMethod[$method];
    }

}