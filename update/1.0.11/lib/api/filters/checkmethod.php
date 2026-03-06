<?php

namespace Awz\BxOrm\Api\Filters;

use Awz\BxOrm\HooksTable;
use Awz\BxOrm\MethodsTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Awz\BxOrm\Helper;
use Awz\BxOrm\Api\Scopes\BaseFilter;

Loc::loadMessages(__FILE__);

class CheckMethod extends BaseFilter {

    /**
     * CheckMethod constructor.
     * @param array $params
     * @param string[] $scopesBx
     * @param Scope[] $scopes
     * @param string[] $scopesRequired
     */
    public function __construct(
        array $params = array(), array $scopesBx = array(),
        array $scopes = array(), array $scopesRequired = array()
    ){
        parent::__construct($params, $scopesBx, $scopes, $scopesRequired);
    }

    public function onBeforeAction(Event $event)
    {
        if(!$this->checkRequire()){
            return null;
        }
        $this->disableScope();
        $appId = null;
        $method = null;

        if(!$appId){
            $appId = $this->getAction()->getController()->getRequest()->get('app');
        }
        if(!$method){
            $method = $this->getAction()->getController()->getRequest()->get('method');
        }

        $checkMethod = null;
        if(!$method || !$appId){
            $checkMethod = false;
        }
        $methodsKeys = [];
        if($method){

            $json = $this->getAction()->getController()->getRequest()->get('json');
            if(is_array($json) && isset($json['params']['name'])){
                $methodAr = explode("/", $method);
                $methodAr[count($methodAr)-1] = $json['params']['name'];
                $method = implode("/", $methodAr);
            }

            $method = \Awz\BxOrm\Helper::replaceMethod($method, $appId);
            $method = strtolower(str_replace('.json','',$method));
            $methodsKeys = explode(".",preg_replace('/([^0-9a-z.\/])/','', $method));
            if(!is_array($methodsKeys)) $checkMethod = false;
            if(is_array($methodsKeys) && (count($methodsKeys)!=2)) {
                $checkMethod = false;
            }
        }

        $METHOD_ID = 0;
        $METHOD_CODE = '';
        if($checkMethod !== false){
            $hookData = HooksTable::getRowById($appId);
            $methodsAppActive = [];
            if(!empty($hookData['METHODS'])){
                /* ib = $methodsKeys[0]
                 * $methodId | request method |
                 * 1         | ib.*           | ib.'.*'
                 * 1.add     | ib.add         | ib.'.'.$methodsKeys[1]
                 * */
                foreach($hookData['METHODS'] as $methodId){

                    $methodId = strtolower($methodId);
                    $tmp = explode('.',$methodId);

                    $methodsAppActive[$tmp[0]] = $methodsAppActive[$tmp[0]] ?? [];

                    if(isset($tmp[1])){
                        $methodsAppActive[$tmp[0]][] = $tmp[1];
                    }else{
                        $methodsAppActive[$tmp[0]][] = '*';
                    }
                }
            }
            //print_r($methodsAppActive);
            //die();
            if(!empty($methodsAppActive)){
                $methodsParamsRes = MethodsTable::getList([
                    'select'=>['*'],
                    'filter'=>['=ID'=>array_keys($methodsAppActive), '=CODE'=>$methodsKeys[0], '=ACTIVE'=>'Y']
                ]);
                while($data = $methodsParamsRes->fetch()){

                    if($checkMethod === true) continue;

                    $appRight = $methodsAppActive[$data['ID']] ?? [];
                    if(empty($appRight)) continue;
                    if(empty($data['PARAMS']['methods'])) continue;

                    foreach($data['PARAMS']['methods'] as $code=>$active){
                        if($active != 'Y') continue;
                        if(
                            ($methodsKeys[1] === $code)
                            && (
                                in_array($code, $appRight)
                                || in_array('*', $appRight)
                            )
                        ){
                            $checkMethod = true;
                            $METHOD_ID = $data['ID'];
                            $METHOD_CODE = $code;
                            break;
                        }
                    }
                }
            }
        }

        if($checkMethod !== true){
            $this->addError(new Error(
                Loc::getMessage('AWZ_BXORM_API_FILTER_CHECKFILTER_ERR'),
                'err_method'
            ));
            return new EventResult(EventResult::ERROR, null, 'awz.bxorm', $this);
        }
        foreach($this->scopesCollection as $scope){
            if($scope->getCode() === 'method'){
                /* @var $scopeCustomData \Awz\BxOrm\Api\Scopes\Parameters */
                $scopeCustomData = $scope->getCustomData();
                if($scopeCustomData instanceof \Awz\BxOrm\Api\Scopes\Parameters){
                    $scopeCustomData->set('METHOD_ID', $METHOD_ID);
                    $scopeCustomData->set('METHOD_CODE', $METHOD_CODE);
                }
            }
        }
        $this->enableScope();

        return new EventResult(EventResult::SUCCESS, null, 'awz.bxorm', $this);
    }

}