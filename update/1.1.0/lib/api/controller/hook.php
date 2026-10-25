<?php
namespace Awz\BxOrm\Api\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Стандартный webhook-транспорт над App: те же методы (call/batch/methods,
 * контроллеры и ORM-сущности), но со стандартными ограничениями параметров —
 * запрещенные ключи в params (кроме MCP-сущности) и POST-only для мутирующих MCP-действий.
 * Для вызовов с любыми параметрами используйте App (action=awz:bxorm.api.app.call).
 * MCP-транспорт — в BxMcp (action=awz:bxorm.api.bxmcp.callrpc).
 */
class Hook extends App
{
    /**
     * Стандартные ограничения вызова: запрещенные ключи params
     * (кроме MCP-сущности — там аргументы инструмента произвольные)
     * и POST-only для мутирующих MCP-действий
     */
    protected function checkCallParams(string $method, array $methodsParams, array $params): bool
    {
        //для MCP-сущности зарезервированные ключи params разрешены — аргументы инструмента произвольные
        $isMcpEntity = in_array(
            mb_strtolower(ltrim((string)$methodsParams['ENTITY'], '\\')),
            ['awz\bxorm\api\controller\mcp\app', 'mcp\app'],
            true
        );
        if(
            !$isMcpEntity &&
            (
                isset($params['id']) || isset($params['method']) || isset($params['order']) ||
                isset($params['select']) || isset($params['filter']) || isset($params['fields']) ||
                isset($params['app']) || isset($params['params'])
            )
        )
        {
            $this->addError(new Error('illegal key in params parameter', -32602));
            $this->addError(new Error('disabled keys: id, method, order, select, filter, fields, app, params', -32602));
            return false;
        }

        //ограничение GET только для MCP-сущности: вызов инструмента и initialize - только POST,
        //обычные (не MCP) методы работают как раньше
        $methodName = explode('.', $method)[1] ?? '';
        if(
            $isMcpEntity &&
            !$this->getRequest()->isPost() &&
            !in_array($methodName, ['tools/list', 'ping'], true)
        ){
            $this->addError(new Error('POST is required for this method', -32600));
            return false;
        }

        return true;
    }

}
