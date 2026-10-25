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

        $params = (is_array($json) && isset($json['params']) && is_array($json['params'])) ? $json['params'] : [];
        $name = (string)($params['name'] ?? '');
        $arguments = (isset($params['arguments']) && is_array($params['arguments'])) ? $params['arguments'] : [];

        if(!$name){
            $name = (string)$this->getRequest()->get('name');
        }
        if(!$name){
            $requestParams = $this->getRequest()->get('params');
            if(is_array($requestParams) && isset($requestParams['name'])){
                $name = (string)$requestParams['name'];
                if(!$arguments && isset($requestParams['arguments']) && is_array($requestParams['arguments'])){
                    $arguments = $requestParams['arguments'];
                }
            }
        }
        $name = strtolower(preg_replace('/[^0-9a-zA-Z_]/is','', $name));

        if(!$name){
            return ['content'=>[['type'=>'text','text'=>'tool name is required']], 'isError'=>true];
        }

        $methodId = $this->getScopeMethodId();

        //сайт-специфичные инструменты: обработчики регистрируются через событие, без правки модуля
        $event = new Event(
            "awz.bxorm",
            "onMcpToolCall",
            ['app'=>$app, 'method_id'=>$methodId, 'name'=>$name, 'arguments'=>$arguments]
        );
        $event->send();
        if($event->getResults()){
            foreach($event->getResults() as $eventResult){
                if($eventResult->getType() == EventResult::SUCCESS){
                    $r = $eventResult->getParameters();
                    if(is_array($r) && array_key_exists('result', $r)){
                        return $r['result'];
                    }
                }
            }
        }

        //встроенные действия Tools нельзя вызвать как инструмент
        if(in_array($name, ['call','list'], true) || !$this->checkToolEnabled($name)){
            return ['content'=>[['type'=>'text','text'=>'tool not found: '.$name]], 'isError'=>true];
        }

        $allParams = $this->getRequest()->getValues();
        foreach($arguments as $key=>$val){
            $allParams[$key] = $val;
        }

        return $this->run($name, [new ParameterDictionary($allParams)]);
    }

    /**
     * ID метода из scope проверки CheckMethod (0 - не определен)
     */
    protected function getScopeMethodId(): int
    {
        $scope = $this->getScopeCollection()->getByCode('method');
        $customData = $scope ? $scope->getCustomData() : null;
        return ($customData instanceof Parameters) ? (int)$customData->get('METHOD_ID') : 0;
    }

    /**
     * Проверяет, что инструмент tools/<name> включен в методе текущего вызова
     */
    protected function checkToolEnabled(string $name): bool
    {
        $methodId = $this->getScopeMethodId();
        if(!$methodId) return false;

        $methodData = MethodsTable::getRowById($methodId);
        if(!$methodData || ($methodData['ACTIVE']!='Y')) return false;
        if(!is_array($methodData['PARAMS']['methods'])) return false;

        return (($methodData['PARAMS']['methods']['tools/'.$name] ?? '') === 'Y');
    }

    /**
     * Возвращает содержимое лог-файла Битрикса (по умолчанию файл из .settings.php секции log)
     */
    public function log_getAction(string $file = '', int $lines = 100)
    {
        try{
            $lines = min(1000, max(1, $lines));
            $requestFile = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file));

            //файл из настроек .settings.php секции log, если не задан явно
            $configured = '';
            $logConfig = (array)(\Bitrix\Main\Config\Configuration::getInstance()->get('log') ?? []);
            $settings = (array)($logConfig['settings'] ?? []);
            if(!empty($settings['file'])){
                $configured = \Bitrix\Main\Application::getDocumentRoot().'/'.ltrim((string)$settings['file'], '/');
            }

            if($requestFile !== ''){
                $path = \Bitrix\Main\Application::getDocumentRoot().'/bitrix/php_interface/'.$requestFile;
            }elseif($configured !== ''){
                $path = $configured;
            }else{
                return [
                    'content' => [['type' => 'text', 'text' => 'Лог не настроен. Сначала вызовите log_set с параметром file.']],
                    'isError' => true
                ];
            }

            if(!is_file($path)){
                return [
                    'content' => [['type' => 'text', 'text' => 'Файл не найден: '.basename($path)]],
                    'isError' => true
                ];
            }

            $text = '';
            if(is_readable($path)){
                $size = (int)filesize($path);
                $readSize = min($size, 200000);
                $fp = fopen($path, 'rb');
                if($fp){
                    fseek($fp, -$readSize, SEEK_END);
                    $text = (string)fread($fp, $readSize);
                    fclose($fp);
                }
            }
            $rows = $text !== '' ? array_slice(explode("\n", $text), -$lines) : ['(файл пуст)'];

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Файл: '.basename($path).' (размер: '.filesize($path).' байт, последние '.count($rows).' строк)'."\n".implode("\n", $rows)
                ]]
            ];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool log_get error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Настраивает лог-файл Битрикса в .settings.php (секция log) — файл Bitrix создаст сам при первой записи
     */
    public function log_setAction(string $file = '', int $sizeMb = 20)
    {
        try{
            $file = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file));
            if($file === '' || $file === '.' || $file === '..'){
                throw new \Exception('не указано имя файла (параметр file)');
            }
            $sizeMb = min(512, max(1, $sizeMb));
            $sizeBytes = $sizeMb * 1024 * 1024;

            $configuration = \Bitrix\Main\Config\Configuration::getInstance();
            $logConfig = (array)($configuration->get('log') ?? []);
            $settings = (array)($logConfig['settings'] ?? []);
            $settings['file'] = 'bitrix/php_interface/'.$file;
            $settings['log_error'] = true;
            $settings['log_file_size'] = $sizeBytes;
            $configuration->add('log', ['settings' => $settings]);
            $configuration->saveConfiguration();

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Лог настроен: /bitrix/php_interface/'.$file.' (создастся при первой записи), максимальный размер до ротации: '.$sizeMb.' МБ ('.$sizeBytes.' байт), log_error: on.'
                ]]
            ];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool log_set error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Служебные файлы, чтение которых запрещено (секреты: пароли БД, ключи, настройки)
     */
    const FILE_READ_BLACKLIST = [
        'dbconn.php',
        '.settings.php',
        '.settings_extra.php',
        '.access.php',
        '.config.php',
        '.primary.cfg.php',
        'after_connect.php',
        'after_connect_d7.php'
    ];

    /**
     * Каталоги-запреты внутри корня сайта (бэкапы, дампы)
     */
    const FILE_READ_BLACKLIST_DIRS = [
        '/bitrix/backup/',
        '/bitrix/php_interface/backup/',
        '/upload/backup/',
        '/upload/bak/'
    ];

    /**
     * Белые списки каталогов для file_get: проверка черных списков и каталогов из опции mcp_file_whitelist
     */
    private function checkFileReadAllowed(string $real): string
    {
        $base = strtolower(basename($real));

        if(in_array($base, self::FILE_READ_BLACKLIST, true)){
            return 'чтение служебного файла запрещено: '.basename($real);
        }
        if(strpos($base, '.env') !== false || preg_match('/\.env[.\-_]/i', $base)){
            return 'чтение служебного файла запрещено: '.basename($real);
        }

        $docRoot = \Bitrix\Main\Application::getDocumentRoot();
        $rootReal = realpath($docRoot);
        $rel = str_replace('\\','/', strtolower(substr($real, (int)strlen($rootReal))));
        foreach(self::FILE_READ_BLACKLIST_DIRS as $dir){
            if(strpos($rel, $dir) === 0){
                return 'чтение служебного каталога запрещено: '.$dir;
            }
        }

        $whitelist = array_filter(array_map('trim', explode(',',
            (string)\Bitrix\Main\Config\Option::get('awz.bxorm', 'mcp_file_whitelist',
                '/bitrix/php_interface/,/bitrix/modules/,/local/,/upload/')
        )));
        foreach($whitelist as $dir){
            $dirReal = realpath($docRoot.'/'.ltrim($dir, '/'));
            if($dirReal && str_starts_with($real, $dirReal)){
                return '';
            }
        }

        return 'путь вне разрешенных каталогов (настройка mcp_file_whitelist)';
    }

    /**
     * Возвращает содержимое файла (путь абсолютный или от корня сайта),
     * целиком или выбранные строки (номер "10", диапазон "10-25", список "1,5,9-12").
     * Для одиночной строки добавляет контекст ±3 строки.
     */
    public function file_getAction(string $file = '', string $lines = '')
    {
        try{
            if(trim($file) === ''){
                return ['content'=>[['type'=>'text','text'=>'не указан путь к файлу (параметр file)']], 'isError'=>true];
            }

            $docRoot = \Bitrix\Main\Application::getDocumentRoot();
            $path = (preg_match('#^[/\\\\]#', $file) || preg_match('#^[a-zA-Z]:[/\\\\]#', $file)) ? $file : $docRoot.'/'.$file;
            $real = realpath($path);
            $rootReal = realpath($docRoot);
            if(!$real || $rootReal === false || !str_starts_with($real, $rootReal)){
                return ['content'=>[['type'=>'text','text'=>'путь вне корня сайта или не существует: '.$file]], 'isError'=>true];
            }

            $denyReason = $this->checkFileReadAllowed($real);
            if($denyReason !== ''){
                return ['content'=>[['type'=>'text','text'=>$denyReason]], 'isError'=>true];
            }

            if(!is_file($real)){
                return ['content'=>[['type'=>'text','text'=>'файл не найден: '.$real]], 'isError'=>true];
            }
            if(!is_readable($real)){
                return ['content'=>[['type'=>'text','text'=>'файл не читается: '.$real]], 'isError'=>true];
            }

            $size = (int)filesize($real);
            $maxSize = 300000;
            $content = '';
            $truncated = false;
            if($size > $maxSize){
                $fp = fopen($real, 'rb');
                if(!$fp){
                    return ['content'=>[['type'=>'text','text'=>'файл не читается: '.$real]], 'isError'=>true];
                }
                $content = (string)fread($fp, $maxSize);
                fclose($fp);
                $truncated = true;
            }else{
                $content = (string)file_get_contents($real);
            }

            $rows = explode("\n", $content);
            $total = count($rows);
            $out = [];

            $ranges = $this->parseLineRanges($lines);
            if($ranges === null){
                //весь файл (первая часть, если обрезан)
                $maxRows = 1000;
                $show = array_slice($rows, 0, $maxRows);
                foreach($show as $i=>$line){
                    $out[] = ($i+1).': '.$line;
                }
                if($total > $maxRows){
                    $out[] = '... (показаны первые '.$maxRows.' строк, всего '.$total.')';
                }elseif($truncated){
                    $out[] = '... (файл больше '.ceil($maxSize/1024).' КБ, показано начало)';
                }
            }else{
                //одиночная строка — контекст ±3
                if(count($ranges) === 1){
                    $lineNum = (int)reset($ranges);
                    $from = max(1, $lineNum-3);
                    $to = min($total, $lineNum+3);
                    for($i=$from; $i<=$to; $i++){
                        $out[] = (($i === $lineNum) ? '> ' : '  ').$i.': '.$rows[$i-1];
                    }
                }else{
                    foreach($ranges as $n){
                        $n = (int)$n;
                        if($n < 1 || $n > $total) continue;
                        $out[] = $n.': '.$rows[$n-1];
                    }
                }
                if($truncated && $total > count($ranges) && max($ranges) > count($rows)){
                    $out[] = '... (файл обрезан по объему, показано начало)';
                }
            }

            $header = 'Файл: '.$real.' (размер: '.$size.' байт, строк: '.$total.')';
            if($lines !== ''){
                $header .= ', запрошено: '.$lines;
            }

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $header."\n".implode("\n", $out)
                ]]
            ];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool file_get error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Разбор спецификации строк: "10", "10-25", "1,5,9-12" → отсортированный список номеров; null — весь файл
     */
    private function parseLineRanges(string $spec): ?array
    {
        $spec = trim($spec);
        if($spec === '') return null;

        $lines = [];
        foreach(explode(',', $spec) as $part){
            $part = trim($part);
            if(preg_match('/^(\d+)\s*-\s*(\d+)$/u', $part, $m)){
                $from = (int)$m[1];
                $to = (int)$m[2];
                if($from > $to){ [$from, $to] = [$to, $from]; }
                if($to - $from > 5000) $to = $from + 5000;
                for($i=$from; $i<=$to; $i++){
                    $lines[$i] = $i;
                }
            }elseif(preg_match('/^\d+$/', $part)){
                $lines[(int)$part] = (int)$part;
            }
        }
        if(empty($lines)) return null;
        sort($lines);
        return $lines;
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

        $descriptions = [
            'envinfo' => 'Возвращает информацию об окружении',
            'phpinfo' => 'Возвращает информацию о php на сайте',
            'cmsinfo' => 'Возвращает информацию о cms и модулях',
            'dbinfo' => 'Возвращает информацию о базе данных',
            'processinfo' => 'Возвращает информацию о нагрузке системы',
            'log_get' => 'Возвращает содержимое лог-файла Битрикса (по умолчанию файл из .settings.php секции log). Последние N строк.',
            'log_set' => 'Настраивает лог-файл Битрикса в .settings.php: создает файл в /bitrix/php_interface/ и задает максимальный размер до ротации (по умолчанию 20 МБ).',
            'file_get' => 'Возвращает содержимое файла (путь абсолютный или от корня сайта) — целиком или выбранные строки. Используй, чтобы прочитать файл, на который указала ошибка в логе.'
        ];

        $schemas = [
            'log_get' => [
                'properties' => [
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Имя файла в /bitrix/php_interface/ (только имя, без путей). По умолчанию — файл из настроек логирования.'
                    ),
                    'lines' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Сколько последних строк вернуть, по умолчанию 100, максимум 1000'
                    ),
                ],
                'required' => []
            ],
            'log_set' => [
                'properties' => [
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Имя лог-файла (например mcp.log), создается в /bitrix/php_interface/ рядом с init.php. Обязательно.'
                    ),
                    'size_mb' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Максимальный размер файла в мегабайтах до ротации, по умолчанию 20, диапазон 1-512'
                    ),
                ],
                'required' => ['file']
            ],
            'file_get' => [
                'properties' => [
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь к файлу: абсолютный (/var/www/...) или от корня сайта (bitrix/modules/.../file.php). Обязательно. Читаются только файлы внутри корня сайта.'
                    ),
                    'lines' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Какие строки вернуть: номер ("10"), диапазон ("10-25") или список через запятую ("1,5,9-12"). Для одиночной строки добавляется контекст ±3 строки. Пусто — весь файл (до 1000 строк).'
                    ),
                ],
                'required' => ['file']
            ]
        ];

        $schemas = [
            'log_get' => [
                'properties' => [
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Имя файла в /bitrix/php_interface/ (только имя, без путей). По умолчанию — файл из настроек логирования.'
                    ),
                    'lines' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Сколько последних строк вернуть, по умолчанию 100, максимум 1000'
                    ),
                ],
                'required' => []
            ],
            'log_set' => [
                'properties' => [
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Имя лог-файла (например mcp.log), создается в /bitrix/php_interface/ рядом с init.php. Обязательно.'
                    ),
                    'size_mb' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Максимальный размер файла в мегабайтах до ротации, по умолчанию 20, диапазон 1-512'
                    ),
                ],
                'required' => ['file']
            ]
        ];

        $tools = [];

        foreach((array)($mcpData['METHODS'] ?? []) as $method){
            $method = strtolower((string)$method);
            $methodAr = explode(".", $method);
            $methodId = (int)($methodAr[0] ?? 0);
            if(!$methodId) continue;
            $appRight = isset($methodAr[1]) ? $methodAr[1] : '*';

            $methodData = \Awz\BxOrm\MethodsTable::getRowById($methodId);
            if(!$methodData || ($methodData['ACTIVE']!='Y')) continue;
            if(!is_array($methodData['PARAMS']['methods'])) continue;

            foreach($methodData['PARAMS']['methods'] as $code=>$active){
                if($active != 'Y') continue;
                if(($appRight != '*') && ($appRight != $code)) continue;
                if(strpos($code, 'tools/') !== 0) continue;
                $toolName = substr($code, strlen('tools/'));
                if(!isset($descriptions[$toolName])) continue;

                $tools[] = new DTO\Tool\ToolListItem(
                    name: $toolName,
                    description: $descriptions[$toolName],
                    inputSchema: new DTO\Tool\InputSchema(
                        type: 'object',
                        properties: ($schemas[$toolName]['properties'] ?? []),
                        required: ($schemas[$toolName]['required'] ?? []),
                    )
                );
            }
        }

        //сайт-специфичные инструменты: обработчики регистрируются через событие, без правки модуля
        $event = new Event(
            "awz.bxorm",
            "onBuildMcpTools",
            ['app'=>$app, 'method_id'=>$this->getScopeMethodId(), 'tools'=>$tools]
        );
        $event->send();
        if($event->getResults()){
            foreach($event->getResults() as $eventResult){
                if($eventResult->getType() == EventResult::SUCCESS){
                    $r = $eventResult->getParameters();
                    if(isset($r['tools']) && is_array($r['tools'])){
                        foreach($r['tools'] as $tool){
                            if($tool instanceof DTO\Tool\ToolListItem){
                                $tools[] = $tool;
                            }
                        }
                    }
                }
            }
        }

        return ["tools"=>$tools];
    }
}