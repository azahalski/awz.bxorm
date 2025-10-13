<?php

namespace Awz\BxOrm\Api\Filters;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Awz\BxOrm\Helper;
use Awz\BxOrm\Api\Scopes\BaseFilter;

Loc::loadMessages(__FILE__);

class AppAuth extends BaseFilter {

    /**
     * AppAuth constructor.
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
        $key = null;
        $appId = null;
        if($this->getAction()->getController()->getRequest()->get('signed')){
            try {
                $signer = new Security\Sign\Signer();
                $params = $signer->unsign($this->getAction()->getController()->getRequest()->get('signed'));
                $params = unserialize(base64_decode($params), ['allowed_classes' => false]);

                $key = $params['key'];
                $appId = $params['app'];

            }catch (\Exception $e){

            }
        }

        if(!$key){
            $key = $this->getAction()->getController()->getRequest()->get('key');
        }
        if(!$key){
            $key = str_replace(["Token ","Bearer "],["",""],
                $this->getAction()->getController()->getRequest()->getHeader('authorization') ?? ""
            );
        }

        if(!$appId){
            $appId = $this->getAction()->getController()->getRequest()->get('app');
        }

        $checkKey = null;
        if(!$key || !$appId){
            $checkKey = false;
        }

        if($checkKey !== false){
            $checkKey = Helper::checkServiceKey($appId, $key);
        }

        if($checkKey !== true){
            $this->addError(new Error(
                Loc::getMessage('AWZ_BXORM_API_FILTER_AUTH_ERR'),
                'err_auth'
            ));
            return new EventResult(EventResult::ERROR, null, 'awz.bxorm', $this);
        }
		
		if(strpos($key, '|')!==false){
            $userAr = explode('|',$key);
            if(count($userAr)==2){
                global $USER;
                $USER->Authorize($userAr[0]);
            }
        }

        $this->enableScope();

        return new EventResult(EventResult::SUCCESS, null, 'awz.bxorm', $this);
    }

}