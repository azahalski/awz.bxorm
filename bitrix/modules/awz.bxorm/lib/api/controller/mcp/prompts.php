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

class Prompts extends App
{
    const CODE_REPLACE = 'prompts/';

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

        $prompts = [];

        //сайт-специфичные промпты: обработчики регистрируются через событие, без правки модуля
        $event = new Event(
            "awz.bxorm",
            "onBuildMcpPrompts",
            ['app'=>(int)$this->getRequest()->get('app'), 'prompts'=>$prompts]
        );
        $event->send();
        if($event->getResults()){
            foreach($event->getResults() as $eventResult){
                if($eventResult->getType() == EventResult::SUCCESS){
                    $r = $eventResult->getParameters();
                    if(isset($r['prompts']) && is_array($r['prompts'])){
                        foreach($r['prompts'] as $prompt){
                            if(is_array($prompt)){
                                $prompts[] = $prompt;
                            }
                        }
                    }
                }
            }
        }

        return [
            "prompts"=>$prompts
        ];
    }

    /**
     * Получение промпта MCP (prompts/get)
     */
    public function getAction(int $app, $json = []){

        $params = (is_array($json) && isset($json['params']) && is_array($json['params'])) ? $json['params'] : [];
        $name = (string)($params['name'] ?? '');
        $arguments = (isset($params['arguments']) && is_array($params['arguments'])) ? $params['arguments'] : [];
        if(!$name){
            $name = (string)$this->getRequest()->get('name');
        }
        if(!$name){
            $this->addError(new Error('name is required', 'err_name'));
            return null;
        }

        //содержимое промпта отдает сайт через событие, без правки модуля
        $event = new Event(
            "awz.bxorm",
            "onGetMcpPrompt",
            ['app'=>$app, 'name'=>$name, 'arguments'=>$arguments]
        );
        $event->send();
        if($event->getResults()){
            foreach($event->getResults() as $eventResult){
                if($eventResult->getType() == EventResult::SUCCESS){
                    $r = $eventResult->getParameters();
                    if(isset($r['prompt']) && is_array($r['prompt'])){
                        return $r['prompt'];
                    }
                }
            }
        }

        $this->addError(new Error('prompt not found: '.$name, -32602));
        return null;
    }
}