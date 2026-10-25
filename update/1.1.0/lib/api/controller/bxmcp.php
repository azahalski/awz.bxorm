<?php
namespace Awz\BxOrm\Api\Controller;

use Awz\BxOrm\Api\Scopes\Parameters;
use Awz\BxOrm\Api\Scopes\Scope;
use Awz\BxOrm\Api\Scopes\Controller;
use Awz\BxOrm\Api\Filters\AppAuth;
use Awz\BxOrm\Api\Filters\CheckMethod;
use Awz\BxOrm\Api\Filters\NoCors;
use Awz\BxOrm\Api\Filters\RpcCheck;
use Awz\BxOrm\Helper;
use Bitrix\Main\Event;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Response;
use Bitrix\Main\Web\Json;

Loc::loadMessages(__FILE__);

/**
 * MCP-транспорт (JSON-RPC 2.0): callrpc и переупаковка ответов.
 * Подключение: /bitrix/services/main/ajax.php?action=awz:bxorm.api.bxmcp.callrpc&app=ID&mcprpc=2.0
 */
class BxMcp extends App
{
    public function configureActions()
    {
        return [
            'callrpc' => [
                'prefilters' => [
                    new RpcCheck([],[]),
                    new NoCors([],[]),
                    //фильтр для проверки авторизации по app и key
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    ),
                    //фильтр для проверки доступности метода (не проверяет авторизацию)
                    new CheckMethod(
                        [], [],
                        [
                            new Scope(
                                'method',
                                Scope::STATUS_OK,
                                new Parameters(['METHOD_ID'=>0])
                            ),
                            new Scope(
                                'method',
                                Scope::STATUS_ERR,
                                new Parameters(['METHOD_ID'=>0])
                            )
                        ],
                        ['user']
                    )
                ]
            ],
        ];
    }

    public function callrpcAction(int $app, $json = []){

        if($this->getRequest()->get('mcprpc') != Helper::MCP_PROTO_VERSION_DEFAULT){
            $this->addError(new Error('mcprpc='.Helper::MCP_PROTO_VERSION_DEFAULT.' is required', -32600));
            return null;
        }

        if(!$this->getRequest()->isPost()){
            $this->addError(new Error('POST is required', -32600));
            return null;
        }

        //валидация JSON-RPC 2.0
        if(!is_array($json) || isset($json[0])){
            $this->addError(new Error('invalid request: single json-rpc object expected', -32600));
            return null;
        }
        if(!isset($json['jsonrpc']) || $json['jsonrpc'] !== '2.0'){
            $this->addError(new Error('invalid request: jsonrpc must be "2.0"', -32600));
            return null;
        }
        if(!isset($json['method']) || !is_string($json['method']) || $json['method'] === ''){
            $this->addError(new Error('invalid request: method must be a string', -32600));
            return null;
        }
        if(isset($json['params']) && !is_array($json['params'])){
            $this->addError(new Error('invalid request: params must be an object', -32600));
            return null;
        }
        if(
            isset($json['id'])
            && !is_string($json['id']) && !is_int($json['id']) && !is_float($json['id'])
        ){
            $this->addError(new Error('invalid request: id must be a string or number', -32602));
            return null;
        }

        $method = $json['method'];
        //подстановки params.name в путь метода нет: tools/call всегда уходит в Mcp\Tools::callAction,
        //где кастомные инструменты обслуживает событие onMcpToolCall, встроенные - checkToolEnabled

        $binderResult = $this->bindCall($app, $method);
        if(!$binderResult->isSuccess()){
            $this->addErrors($binderResult->getErrors());
            return null;
        }
        if(!empty($binderResult->getData())){
            return $binderResult->getData();
        }

        $method = $binderResult->getMethod();

        $methodsParams = $this->resolveMethodParams();
        if(!$methodsParams){
            return null;
        }

        $allParams = $this->getRequest()->toArray();
        $allParams['app'] = $app;

        //контроллер
        if($this->isControllerEntity($methodsParams['ENTITY'])){
            return $this->dispatchToController($method, $methodsParams, $allParams);
        }

        //не orm (MCP-транспорт ORM-методы не поддерживает — только контроллеры)
        if(!($methodsParams['ENTITY'] && is_string($methodsParams['ENTITY']) && class_exists($methodsParams['ENTITY']))){
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_PARAM_M_ERR')));
            return null;
        }
        return null;

    }

    public function finalizeResponse(Response $response): void
    {
        if(self::finalizeMcpResponse($response, $this)) return;
        parent::finalizeResponse($response);
    }

    /**
     * Переупаковка ответа в JSON-RPC 2.0.
     *
     * @param Response $response
     * @param Controller $controller — контроллер, обработавший вызов (доступны его ошибки)
     * @return bool true — ответ финализирован (notification, 202), дальнейший finalize не нужен
     */
    public static function finalizeMcpResponse(Response $response, Controller $controller): bool
    {
        $isAuthError = false;
        foreach($controller->getErrors() as $error){
            if($error->getCode() === 'err_auth'){
                $isAuthError = true;
                break;
            }
        }
        if($isAuthError){
            $response->setStatus('401 Unauthorized');
        }

        if($controller->getRequest()->get('mcprpc') != Helper::MCP_PROTO_VERSION_DEFAULT){
            return false;
        }

        $requestData = $controller->getJsonRequestData();

        //notifications не порождают ответа
        if(
            is_array($requestData)
            && isset($requestData['method'])
            && !isset($requestData['id'])
            && strpos((string)$requestData['method'], 'notifications/') === 0
        ){
            $response->setStatus('202 Accepted');
            $response->setContent('');
            return true;
        }

        $id = $requestData['id'] ?? null;
        if(is_array($id) && empty($id)){
            $id = new \stdClass();
        }

        $errors = $controller->getErrors();

        $content = $response->getContent();
        $jsonResult = null;
        if ($content) {
            /**
             * Ответ действия контроллера Bitrix ([status => ... , data => ...]).
             * Нельзя декодировать в массив, т.к. некоторые объекты потом могут не вернуть свой тип.
             */
            $jsonResult = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        }

        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        if (empty($errors) && is_object($jsonResult) && $jsonResult->status === 'success') {
            $finalResponse = [
                'jsonrpc' => Helper::MCP_PROTO_VERSION_DEFAULT,
                'result' => $jsonResult->data->result,
                'id' => $id,
            ];
            $response->setContent(Json::encode($finalResponse));
        } else {
            $errorMessages = array_map(fn($error) => $error->getMessage(), $errors);
            $errorCode = -32603;
            foreach($errors as $error){
                if((int)$error->getCode() < 0){
                    $errorCode = (int)$error->getCode();
                    break;
                }
            }
            $finalResponse = [
                'jsonrpc' => Helper::MCP_PROTO_VERSION_DEFAULT,
                'error' => ['code' => $errorCode, 'message' => implode(', ', $errorMessages)],
                'id' => $id,
            ];
            $response->setContent(Json::encode($finalResponse));
        }

        return false;
    }

}
