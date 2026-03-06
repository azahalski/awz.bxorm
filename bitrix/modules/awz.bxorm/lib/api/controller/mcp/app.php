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

class App extends Controller
{
    const MCP_SERVER_VERSION = '1.0.1';
    const MCP_PROTO_VERSION = '2025-06-18';

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
        return static::MCP_SERVER_VERSION;
    }

    public static function getProtoVersion(){
        return static::MCP_PROTO_VERSION;
    }

    public function initializeAction(){

        $mcpData = \Awz\BxOrm\HooksTable::getRowById($this->getRequest()->get('app'));

        return [
            'protocolVersion' => static::getProtoVersion(),
            'capabilities' => [
                'tools' => new \stdClass(),
                'prompts' => new \stdClass(),
                'resources' => new \stdClass(),
                'logging' => new \stdClass()
            ],
            'serverInfo' => [
                'name' => $mcpData['NAME'],
                'version' => static::getMcpServerVersion(),
            ]
        ];
    }

}