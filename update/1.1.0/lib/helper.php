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

    const MCP_METHOD_CODE = 'mcp';
    const MCP_ENTITY = '\Awz\BxOrm\Api\Controller\Mcp\App';
    const MCP_HOOK_NAME = 'MCP AI (авто)';

    /**
     * Действия MCP-контроллера, включаемые в методе для ИИ
     * @return string[]
     */
    public static function getMcpActions(): array
    {
        return [
            'initialize',
            'ping',
            'tools/list',
            'tools/call',
            'resources/list',
            'resources/read',
            'prompts/list',
            'prompts/get',
            'tools/envinfo',
            'tools/phpinfo',
            'tools/cmsinfo',
            'tools/dbinfo',
            'tools/processinfo',
            'tools/log_get',
            'tools/log_set',
            'tools/file_get'
        ];
    }

    /**
     * ID метода MCP-сервера (0 - если не создан)
     */
    public static function getMcpMethodId(): int
    {
        $r = MethodsTable::getList([
            'select'=>['ID'],
            'filter'=>['=CODE'=>self::MCP_METHOD_CODE, '=ENTITY'=>self::MCP_ENTITY],
            'limit'=>1
        ])->fetch();
        return $r ? (int)$r['ID'] : 0;
    }

    /**
     * Хук MCP-сервера (null - если не создан)
     */
    public static function getMcpHook(): ?array
    {
        $r = HooksTable::getList([
            'filter'=>['=NAME'=>self::MCP_HOOK_NAME],
            'limit'=>1
        ])->fetch();
        return $r ?: null;
    }

    /**
     * Создает (или обновляет) метод и хук MCP-сервера для подключения ИИ
     */
    public static function createMcpApp(): Result
    {
        $result = new Result();

        $methodId = self::getMcpMethodId();
        $methodFields = [
            'NAME' => 'MCP: инструменты для ИИ',
            'CODE' => self::MCP_METHOD_CODE,
            'ENTITY' => self::MCP_ENTITY,
            'MODULES' => ['main'],
            'ACTIVE' => 'Y',
            'PARAMS' => [
                'methods' => array_fill_keys(self::getMcpActions(), 'Y'),
                'fields' => []
            ]
        ];

        if($methodId){
            $r = MethodsTable::update($methodId, $methodFields);
        }else{
            $r = MethodsTable::add($methodFields);
            $methodId = (int)$r->getId();
        }
        if(!$r->isSuccess()){
            $result->addErrors($r->getErrors());
            return $result;
        }

        $hookData = self::getMcpHook();
        $hookFields = [
            'NAME' => self::MCP_HOOK_NAME,
            'TOKEN' => self::generateToken(),
            'ACTIVE' => 'Y',
            'METHODS' => [$methodId]
        ];
        if($hookData){
            $r = HooksTable::update($hookData['ID'], $hookFields);
            $appId = (int)$hookData['ID'];
        }else{
            $r = HooksTable::add($hookFields);
            $appId = (int)$r->getId();
        }
        if(!$r->isSuccess()){
            $result->addErrors($r->getErrors());
            return $result;
        }

        $result->setData([
            'APP_ID' => $appId,
            'TOKEN' => $hookFields['TOKEN'],
            'METHOD_ID' => $methodId,
            'URL' => self::getMcpUrl($appId)
        ]);
        return $result;
    }

    /**
     * Удаляет метод и хуки MCP-сервера
     */
    public static function deleteMcpApp(): Result
    {
        $result = new Result();

        $methodId = self::getMcpMethodId();
        if(!$methodId){
            $result->addError(new Error(Loc::getMessage('AWZ_BXORM_HELPER_MCP_NOT_FOUND')));
            return $result;
        }

        $r = HooksTable::getList(['filter'=>['=NAME'=>self::MCP_HOOK_NAME]]);
        while($hook = $r->fetch()){
            $methods = is_array($hook['METHODS']) ? $hook['METHODS'] : [];
            if(!in_array($methodId, $methods)) continue;
            $del = HooksTable::delete($hook['ID']);
            if(!$del->isSuccess()){
                $result->addErrors($del->getErrors());
                return $result;
            }
        }

        $del = MethodsTable::delete($methodId);
        if(!$del->isSuccess()){
            $result->addErrors($del->getErrors());
            return $result;
        }

        return $result;
    }

    /**
     * URL для подключения MCP-клиента (ИИ)
     */
    public static function getMcpUrl(int $appId, string $token = ''): string
    {
        $host = Application::getInstance()->getContext()->getServer()->getHttpHost();
        $url = 'https://'.$host.'/bitrix/services/main/ajax.php?action=awz:bxorm.api.bxmcp.callrpc&app='.$appId.'&mcprpc=2.0';
        if($token){
            $url .= '&key='.$token;
        }
        return $url;
    }

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
        if($r['TOKEN'] && hash_equals((string)$r['TOKEN'], $key)) {
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