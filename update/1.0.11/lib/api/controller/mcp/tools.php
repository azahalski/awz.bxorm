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
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;

use awz\bxorm\api\controller\mcp\DTO;

Loc::loadMessages(__FILE__);

class Tools extends App
{
    const CODE_REPLACE = 'tools/';

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

    public function callAction(int $app, $json = []){

        if($json['params']['name']){
            return $this->forward($this::class, $json['params']['name']);
        }

        return [];
    }

    /**
     * Возвращает информацию о Битрикс-окружении
     *
     * @return []
     */
    public function cmsinfoAction(): array
    {
        $content = [
            ["type"=>"text","text"=>'Версия CMS Битрикс: '.SM_VERSION],
            ["type"=>"text","text"=>'Редакция CMS: '.Option::get('main', '~license_name', '')]
        ];

        $modules = [];
        $bxModules = ModuleManager::getModulesFromDisk();
        foreach ($bxModules as $module => $info) {
            if (!empty($info['name']) && !empty($info['version'])) {
                $modules[] = '['.$module.'] '.$info['version'].' - '.$info['name'];
            }
        }
        $content[] = ["type"=>"text","text"=>'Установленные модули: '.implode("; ", $modules)];

        return ['content'=>$content];
    }

    /**
     * Информация о нагрузках на сервере
     *
     * @return []
     */
    public function processinfoAction(): array
    {
        $output1 = shell_exec('top -b -n 1');
        $output2 = shell_exec('free -m');
        $output3 = shell_exec('nproc');

        $content = [
            ["type"=>"text","text"=>'вывод команды top: '.$output1],
            ["type"=>"text","text"=>'вывод команды free: '.$output2],
            ["type"=>"text","text"=>'nproc: '.$output3],
        ];

        return ['content'=>$content];
    }

    /**
     * Возвращает информацию о базе данных
     *
     * @return []
     */
    public function dbinfoAction(): array
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $content = [
            ["type"=>"text","text"=>'type: '.$connection->getType()],
            ["type"=>"text","text"=>'version: '.current($connection->getVersion())],
            ["type"=>"text","text"=>'host: '.$connection->getHost()],
            ["type"=>"text","text"=>'name: '.$connection->getDatabase()],
        ];
        return ['content'=>$content];
    }

    /**
     * Возвращает информацию о PHP
     *
     * @return []
     */
    public function phpinfoAction(): array
    {
        $content = [
            ["type"=>"text","text"=>'version: '.PHP_VERSION],
            ["type"=>"text","text"=>'extensions: '.implode(", ", get_loaded_extensions())],
            ["type"=>"text","text"=>'memoryLimit: '.ini_get('memory_limit')],
            ["type"=>"text","text"=>'maxExecutionTime: '.ini_get('max_execution_time')],
        ];
        return ['content'=>$content];
    }

    /**
     * Возвращает информацию о системе
     *
     * @return []
     */
    public function envinfoAction(): array
    {

        $content = [
            ["type"=>"text","text"=>'Операционная система: '.php_uname()],
            ["type"=>"text","text"=>'Имя хоста сервера: '.gethostname()],
            ["type"=>"text","text"=>'Имя текущего пользователя сервера: '.get_current_user()],
            ["type"=>"text","text"=>'Объем диска в байтах: '.@disk_total_space(Application::getDocumentRoot()) ?: null],
            ["type"=>"text","text"=>'Объем свободного места на диске в байтах: '.@disk_free_space(Application::getDocumentRoot()) ?: null]
        ];

        return ['content'=>$content];
    }

    public function listAction(int $app, $json = []){

        $mcpData = \Awz\BxOrm\HooksTable::getRowById($this->getRequest()->get('app'));

        $tools = [];

        foreach($mcpData['METHODS'] as $method){
            $methodAr = explode(".",$method);
            if(count($methodAr)==2){
                if($methodAr[1] == 'tools/envinfo'){
                    $tools[] = new DTO\Tool\ToolListItem(
                        name: 'envinfo',
                        description: 'Возвращает информацию об окружении',
                        inputSchema: new DTO\Tool\InputSchema(
                            type: 'object',
                            properties: [],
                            required: [],
                        )
                    );
                }
                if($methodAr[1] == 'tools/phpinfo'){
                    $tools[] = new DTO\Tool\ToolListItem(
                        name: 'phpinfo',
                        description: 'Возвращает информацию о php на сайте',
                        inputSchema: new DTO\Tool\InputSchema(
                            type: 'object',
                            properties: [],
                            required: [],
                        )
                    );
                }
                if($methodAr[1] == 'tools/cmsinfo'){
                    $tools[] = new DTO\Tool\ToolListItem(
                        name: 'cmsinfo',
                        description: 'Возвращает информацию о cms и модулях',
                        inputSchema: new DTO\Tool\InputSchema(
                            type: 'object',
                            properties: [],
                            required: [],
                        )
                    );
                }
                if($methodAr[1] == 'tools/dbinfo'){
                    $tools[] = new DTO\Tool\ToolListItem(
                        name: 'dbinfo',
                        description: 'Возвращает информацию о базе данных',
                        inputSchema: new DTO\Tool\InputSchema(
                            type: 'object',
                            properties: [],
                            required: [],
                        )
                    );
                }
                if($methodAr[1] == 'tools/processinfo'){
                    $tools[] = new DTO\Tool\ToolListItem(
                        name: 'processinfo',
                        description: 'Возвращает информацию о нагрузке системы',
                        inputSchema: new DTO\Tool\InputSchema(
                            type: 'object',
                            properties: [],
                            required: [],
                        )
                    );
                }
            }
        }

        return ["tools"=>$tools];
    }
}