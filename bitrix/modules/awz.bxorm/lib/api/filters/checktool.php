<?php

namespace Awz\BxOrm\Api\Filters;

use Awz\BxOrm\HooksTable;
use Awz\BxOrm\MethodsTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Awz\BxOrm\Api\Scopes\BaseFilter;

Loc::loadMessages(__FILE__);

/**
 * Гейтинг MCP-инструментов tools/<код> для прямых маршрутов под-контроллера Tools
 * (awz:bxorm.api.mcp.tools.<код> и вызовов через tools/call):
 * инструмент должен быть включен в активном методе MCP-сущности
 * (PARAMS.methods = 'tools/<код>' = Y), и этот метод должен входить в хук текущего app
 * (полностью 'METHOD_ID' либо с нужным действием 'METHOD_ID.tools/<код>').
 */
class CheckTool extends BaseFilter {

    /**
     * Действия Tools, не являющиеся инструментами (не гейтятся)
     */
    const NON_TOOL_ACTIONS = ['call', 'list'];

    public function onBeforeAction(Event $event)
    {
        if(!$this->checkRequire()){
            return null;
        }
        $this->disableScope();

        $tool = '';
        $action = $this->getAction();
        if($action){
            $tool = strtolower((string)$action->getName());
        }
        $tool = strtolower(preg_replace('/[^0-9a-zA-Z_]/is','', $tool));

        $allowed = false;
        if($tool !== '' && !in_array($tool, self::NON_TOOL_ACTIONS, true)){
            $request = $this->getAction()->getController()->getRequest();
            $appId = (int)$request->get('app');
            if($appId > 0){
                $hookData = HooksTable::getRowById($appId);
                if($hookData && ($hookData['ACTIVE']=='Y') && !empty($hookData['METHODS'])){
                    foreach($hookData['METHODS'] as $hookMethod){
                        $hookMethod = strtolower((string)$hookMethod);
                        $tmp = explode('.', $hookMethod);
                        $methodId = (int)($tmp[0] ?? 0);
                        if(!$methodId) continue;
                        $appRight = isset($tmp[1]) ? [$tmp[1]] : ['*'];
                        $methodData = MethodsTable::getRowById($methodId);
                        if(!$methodData || ($methodData['ACTIVE']!='Y')) continue;
                        //метод должен быть MCP-сущностью
                        $entity = strtolower(ltrim((string)$methodData['ENTITY'], '\\'));
                        if(!in_array($entity, ['awz\bxorm\api\controller\mcp\app', 'mcp\app'], true)) continue;
                        if(!in_array('*', $appRight, true) && !in_array('tools/'.$tool, $appRight, true)) continue;
                        if(!is_array($methodData['PARAMS'])) continue;
                        $methodsMap = (array)($methodData['PARAMS']['methods'] ?? []);
                        if(($methodsMap['tools/'.$tool] ?? '') !== 'Y') continue;
                        $allowed = true;
                        break;
                    }
                }
            }
        }

        if(!$allowed){
            $this->addError(new Error(
                'tool not found: '.$tool,
                -32601
            ));
            return new EventResult(EventResult::ERROR, null, 'awz.bxorm', $this);
        }

        $this->enableScope();
        return new EventResult(EventResult::SUCCESS, null, 'awz.bxorm', $this);
    }
}
