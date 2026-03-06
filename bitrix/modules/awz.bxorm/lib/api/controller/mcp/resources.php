<?php
namespace Awz\BxOrm\Api\Controller\Mcp;

use Awz\BxOrm\Api\BinderResult;
use Awz\BxOrm\Api\Scopes\Parameters;
use Awz\BxOrm\Api\Scopes\Scope;
use Awz\BxOrm\Api\Scopes\Controller;
use Awz\BxOrm\Api\Filters\CheckMethod;
use Awz\BxOrm\Api\Filters\AppAuth;
use Awz\BxOrm\Api\Filters\NoCors;
use Awz\BxOrm\HooksTable;
use Awz\BxOrm\MethodsTable;
use Awz\BxOrm\Helper;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Request;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Response;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;
use Awz\BxOrm\Api\Filters\Request\ReplaceFilter;
use Bitrix\Main\Type\ParameterDictionary;

Loc::loadMessages(__FILE__);

class Resources extends App
{
    const CODE_REPLACE = 'resources/';

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
    }
    public function configureActions()
    {
        $tmp = parent::configureActions();
        $config = [];
        foreach($tmp as $code=>$val){
            $code = strtolower($code);
            if(strpos($code, self::CODE_REPLACE)!==false){
                $config[str_replace(self::CODE_REPLACE, '', $code)] = $val;
            }
        }
        return $config;
    }

    public function listAction(){
        return [
            "resources"=>[]
        ];
    }
}