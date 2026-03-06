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

class RpcCheck extends BaseFilter {

    /**
     * RpcCheck constructor.
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
        $httpRequest = $this->getAction()->getController()->getRequest();
        if($httpRequest->get('mcprpc')){
            $httpRequest->addFilter(new Request\Rpc($httpRequest->getInput()));
        }
    }

}