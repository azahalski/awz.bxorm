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
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class App extends Controller
{
    public static string $MCP_SERVER_VERSION = '1.5.0';

    public static string $MCP_PROTO_VERSION = '2025-06-18';

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
    }

    public function configureActions()
    {
        $config = [
            'initialize' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'resources/list' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'resources/read' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'prompts/list' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'prompts/get' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/list' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/log_get' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/log_set' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/file_get' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/call' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'ping' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/envinfo'=>[
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/dbinfo'=>[
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/cmsinfo'=>[
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/phpinfo'=>[
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ],
            'tools/processinfo'=>[
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ],
            ]
        ];
        return $config;
    }

    public static function getMcpServerVersion(){
        $version = trim((string)Option::get('awz.bxorm', 'mcp_server_version', static::$MCP_SERVER_VERSION));
        return $version !== '' ? $version : static::$MCP_SERVER_VERSION;
    }

    public static function getProtoVersion(){
        $version = trim((string)Option::get('awz.bxorm', 'mcp_proto_version', static::$MCP_PROTO_VERSION));
        return $version !== '' ? $version : static::$MCP_PROTO_VERSION;
    }

    public function initializeAction(int $app, $json = []){

        $clientVersion = '';
        if(is_array($json) && isset($json['params']['protocolVersion'])){
            $clientVersion = (string)$json['params']['protocolVersion'];
        }

        //по спеке при несовпадении версий сервер отвечает СВОЕЙ последней версией,
        //решение о совместимости принимает клиент (а не отказ -32000)
        if($clientVersion === ''){
            $this->addError(new Error(
                'protocolVersion is required (supported: '.static::getProtoVersion().')',
                -32602
            ));
            return null;
        }

        $mcpData = \Awz\BxOrm\HooksTable::getRowById($this->getRequest()->get('app'));

        return [
            'protocolVersion' => static::getProtoVersion(),
            'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
                'resources' => new \stdClass()
            ],
            'serverInfo' => [
                'name' => $mcpData['NAME'],
                'version' => static::getMcpServerVersion(),
            ]
        ];
    }

    /**
     * Проверка живости соединения (MCP ping)
     */
    public function pingAction()
    {
        return new \stdClass();
    }

}