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
     * Определяет путь лог-файла по настройкам (не от пользователя):
     * type=exception_handling — файл из .settings.php секции log (settings.file, может быть полным путем);
     * type=<имя логгера> — файл из constructorParams логгера \Bitrix\Main\Diag\FileLogger.
     * Возвращает ['ok'=>bool, 'path'=>string, 'msg'=>string, 'configured'=>bool]
     * (configured=false — лог не настроен/отключен; ok=true, файла может еще не существовать)
     */
    private function resolveLogFilePath(string $type): array
    {
        $res = ['ok' => false, 'path' => '', 'msg' => '', 'configured' => false];
        $configuration = \Bitrix\Main\Config\Configuration::getInstance();
        if($type === 'exception_handling'){
            $logConfig = (array)($configuration->get('exception_handling') ?? []);
            $settings = (array)($logConfig['log']['settings'] ?? []);
            if(empty($logConfig['debug'])){
                $res['msg'] = 'Лог отключен. Сначала вызовите log_set с параметром file.';
                return $res;
            }
            $res['configured'] = true;
            $settingsFile = str_replace('\\', '/', (string)($settings['file'] ?? ''));
            if($settingsFile === ''){
                $res['msg'] = 'Лог не настроен. Сначала вызовите log_set с параметром file.';
                return $res;
            }
            //в settings.file может быть указан полный путь (абсолютный или от корня диска)
            $res['path'] = preg_match('#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $settingsFile)
                ? $settingsFile
                : \Bitrix\Main\Application::getDocumentRoot().'/'.ltrim($settingsFile, '/');
        }else{
            $loggers = (array)($configuration->get('loggers') ?? []);
            //поддержка обеих структур: правильная get() => [имя=>логгер] и старая битая ['value'=>[...]]
            if(isset($loggers['value']) && is_array($loggers['value'])){
                $loggers = $loggers['value'];
            }
            if(!isset($loggers[$type])){
                $res['msg'] = 'Логгер не найден в .settings.php (секция loggers): '.$type;
                return $res;
            }
            $res['configured'] = true;
            $logger = (array)$loggers[$type];
            $className = (string)($logger['className'] ?? '');
            if($className !== '\\'.'Bitrix\\Main\\Diag\\FileLogger' && strtolower($className) !== strtolower('\\Bitrix\\Main\\Diag\\FileLogger')){
                $res['msg'] = 'Работают только логи класса \Bitrix\Main\Diag\FileLogger, у логгера: '.$className;
                return $res;
            }
            $cp = $logger['constructorParams'] ?? null;
            if($cp instanceof \Closure){
                $cpArgs = (array)$cp();
            }elseif(is_string($cp) && $cp !== ''){
                $cpArgs = [$cp];
            }elseif(is_array($cp)){
                $cpArgs = $cp;
            }else{
                $cpArgs = [];
            }
            $cpFile = str_replace('\\', '/', (string)($cpArgs[0] ?? ''));
            if($cpFile === ''){
                $res['msg'] = 'Не удалось определить файл логгера из constructorParams: '.$type;
                return $res;
            }
            $res['path'] = (preg_match('#^[/\\\\]#', $cpFile) || preg_match('#^[a-zA-Z]:[/\\\\]#', $cpFile))
                ? $cpFile
                : \Bitrix\Main\Application::getDocumentRoot().'/'.ltrim($cpFile, '/');
        }
        $res['ok'] = true;
        return $res;
    }

    /**
     * Возвращает содержимое лог-файла Битрикса.
     * Путь задается только настройками (не передается пользователем):
     * - type=exception_handling (по умолчанию) — файл из .settings.php секции log (settings.file);
     * - type=<имя логгера> — файл PSR-логгера из .settings.php секции loggers
     *   (например awz.bxorm.App — берется из constructorParams логгера \Bitrix\Main\Diag\FileLogger).
     */
    public function log_getAction(string $type = 'exception_handling', int $lines = 100)
    {
        try{
            $lines = min(1000, max(1, $lines));
            $type = trim($type);
            if($type === '') $type = 'exception_handling';

            $resolved = $this->resolveLogFilePath($type);
            if(!$resolved['ok']){
                return [
                    'content' => [['type' => 'text', 'text' => $resolved['msg']]],
                    'isError' => true
                ];
            }
            $path = $resolved['path'];

            if(!is_file($path)){
                //файл может быть еще не создан — Bitrix создает его при первой записи
                if($resolved['configured']){
                    return [
                        'content' => [['type' => 'text', 'text' => 'Лог настроен, но файл ещё не создан (создается при первой записи): '.$path
                            .'. Если записей нет — проверьте, что вызовы действительно генерируют ошибки/записи.']]
                    ];
                }
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
     * Отключает логирование.
     * type=exception_handling (по умолчанию) — удаляется секция log из exception_handling в .settings.php.
     * type=<имя логгера> — логгер удаляется из секции loggers.
     */
    public function log_offAction(string $type = 'exception_handling')
    {
        try{
            $configuration = \Bitrix\Main\Config\Configuration::getInstance();
            $type = trim($type);
            if($type === '') $type = 'exception_handling';

            //.settings.php должен быть доступен на запись ДО изменения конфига
            $docRootOff = \Bitrix\Main\Application::getDocumentRoot();
            $settingsPathOff = $docRootOff.'/bitrix/.settings.php';
            if(!is_file($settingsPathOff)) $settingsPathOff = $docRootOff.'/.settings.php';
            if(is_file($settingsPathOff) && !is_writable($settingsPathOff)){
                throw new \Exception('файл настроек недоступен для записи: '.$settingsPathOff.' — конфиг не изменен, проверьте права');
            }

            if($type === 'exception_handling'){
                $logConfig = (array)($configuration->get('exception_handling') ?? []);
                $logConfig['debug'] = false;
                if(isset($logConfig['log'])){
                    unset($logConfig['log']);
                }
                //мусорные верхнеуровневые ключи (остатки прошлых версий: 'settings', NULL-секции) — убираем
                if(isset($logConfig['settings'])){
                    unset($logConfig['settings']);
                }
                $configuration->add('exception_handling', $logConfig);
                $this->sanitizeLoggersForSave();
                $configuration->saveConfiguration();
                return [
                    'content' => [['type' => 'text', 'text' => 'Системный лог отключен: debug=false, секция log удалена из exception_handling в .settings.php.']]
                ];
            }else{
                $type = preg_replace('/[^0-9a-zA-Z._-]/', '', $type);
                $loggersConfig = (array)($configuration->get('loggers') ?? []);
                if(isset($loggersConfig['value']) && is_array($loggersConfig['value'])){
                    //старая битая структура (двойная вложенность value.value)
                    $loggers = (array)$loggersConfig['value'];
                }else{
                    $loggers = $loggersConfig;
                }
                if(!isset($loggers[$type])){
                    return [
                        'content' => [['type' => 'text', 'text' => 'Логгер не найден в .settings.php (секция loggers): '.$type]],
                        'isError' => true
                    ];
                }
                unset($loggers[$type]);
                if(!empty($loggers)){
                    //add() сам оборачивает в 'value' — передаем карту напрямую
                    $configuration->add('loggers', $loggers);
                }else{
                    $configuration->delete('loggers');
                }
                $dropped = $this->sanitizeLoggersForSave();
                $configuration->saveConfiguration();
                return [
                    'content' => [['type' => 'text', 'text' => (empty($loggers)
                        ? 'PSR-логгер «'.$type.'» удален: секция loggers полностью удалена из .settings.php, логирование через него отключено.'
                        : 'PSR-логгер «'.$type.'» удален из секции loggers, логирование через него отключено.')
                        .(($dropped) ? ' Из сохранения удалены логгеры с несериализуемыми constructorParams (Closure): '.implode(', ', $dropped) : '')]]
                ];
            }
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool log_off error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Усекает (ротирует) лог-файл: содержимое обнуляется, файл и настройки сохраняются.
     * type=exception_handling (по умолчанию) — файл из секции log .settings.php;
     * type=<имя логгера> — файл PSR-логгера из секции loggers (\Bitrix\Main\Diag\FileLogger).
     * file — необязательный явный путь (абсолютный или от корня сайта, внутри DOCUMENT_ROOT,
     * те же ограничения, что у file_get).
     */
    public function log_clearAction(string $type = 'exception_handling', string $file = '')
    {
        try{
            $type = trim($type);
            if($type === '') $type = 'exception_handling';

            if(trim($file) !== ''){
                //явный путь: те же проверки, что в file_get
                $docRoot = \Bitrix\Main\Application::getDocumentRoot();
                $path = (preg_match('#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $file)) ? $file : $docRoot.'/'.$file;
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
            }else{
                $resolved = $this->resolveLogFilePath($type);
                if(!$resolved['ok']){
                    return ['content'=>[['type'=>'text','text'=>$resolved['msg']]], 'isError'=>true];
                }
                $real = realpath($resolved['path']);
                if(!$real || !is_file($real)){
                    return ['content'=>[['type'=>'text','text'=>'Файл ещё не создан, усекать нечего: '.$resolved['path']]]];
                }
            }

            if(!is_writable($real)){
                return ['content'=>[['type'=>'text','text'=>'файл недоступен для записи: '.$real]], 'isError'=>true];
            }
            $sizeBefore = (int)filesize($real);
            if(file_put_contents($real, '') === false){
                return ['content'=>[['type'=>'text','text'=>'не удалось усечь файл: '.$real]], 'isError'=>true];
            }
            clearstatcache(true, $real);

            return [
                'content' => [['type' => 'text', 'text' => 'Лог усечен: '.$real
                    .' (было: '.$sizeBefore.' байт, стало: '.filesize($real).' байт). Логирование продолжает работать.']]
            ];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool log_clear error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Санитизация секции loggers перед saveConfiguration: var_export не умеет сериализовать
     * Closure (constructorParams чужих логгеров) — в .settings.php попадает \Closure::__set_state
     * и сайт падает 500. Closure вызывается (так же делает Diag\Logger) для получения аргументов;
     * если вызвать не удалось — логгер удаляется из сохраняемой секции.
     * Возвращает список удаленных логгеров.
     */
    private function sanitizeLoggersForSave(): array
    {
        $configuration = \Bitrix\Main\Config\Configuration::getInstance();
        $loggers = (array)($configuration->get('loggers') ?? []);
        if(isset($loggers['value']) && is_array($loggers['value'])){
            $loggers = $loggers['value'];
        }
        $fixed = [];
        $dropped = [];
        foreach($loggers as $id => $entry){
            if(!is_array($entry)){
                $fixed[$id] = $entry;
                continue;
            }
            $cp = $entry['constructorParams'] ?? null;
            if($cp instanceof \Closure){
                try{
                    $args = $cp();
                }catch(\Throwable $e){
                    $args = null;
                }
                if(is_array($args)){
                    $entry['constructorParams'] = $args;
                    $fixed[$id] = $entry;
                }else{
                    $dropped[] = (string)$id;
                }
            }else{
                $fixed[$id] = $entry;
            }
        }
        if(!empty($fixed)){
            $configuration->add('loggers', $fixed);
        }else{
            $configuration->delete('loggers');
        }
        return $dropped;
    }

    /**
     * Настраивает лог-файл Битрикса.
     * type=exception_handling (по умолчанию) — стандартный лог: секция log в .settings.php.
     * type=<имя логгера> — PSR-лог: создается/обновляется логгер с этим именем в секции loggers.
     * params — дополнительные параметры секции log/settings (class_name, log_size, log_error,
     * log_file_size, date_format, trace_format и т.п.) — JSON-строка или ассоциативный массив.
     */
    public function log_setAction(string $file = '', $size_mb = 20, string $level = 'debug', string $type = 'exception_handling', $params = [])
    {
        try{
            //параметры берем напрямую из запроса (binder может не сматчить имена аргументов)
            $req = $this->getRequest();
            $reqGet = function(string $key) use ($req){
                $val = $req->get($key);
                return is_string($val) ? $val : (is_scalar($val) ? (string)$val : null);
            };
            $rFile = $reqGet('file');
            if($rFile !== null && trim($rFile) !== '') $file = $rFile;
            $rSize = $reqGet('size_mb');
            if($rSize !== null && trim($rSize) !== '') $size_mb = $rSize;
            $rLevel = $reqGet('level');
            if($rLevel !== null && trim($rLevel) !== '') $level = $rLevel;
            $rType = $reqGet('type');
            if($rType !== null && trim($rType) !== '') $type = $rType;
            $rParams = $req->get('params');
            if(is_string($rParams) && trim($rParams) !== '') $params = $rParams;
            elseif(is_array($rParams) && !empty($rParams) && !isset($rParams['name']) && !isset($rParams['arguments'])) $params = $rParams;

            $sizeMb = (int)$size_mb;
            //в file можно передать либо имя файла (каталог выбирается автоматически),
            //либо полный путь (абсолютный, например /var/www/.../exceptions.log)
            $fileRaw = str_replace('\\', '/', trim($file));
            $isFullPath = (bool)preg_match('#^([/\\\\]|[a-zA-Z]:[/\\\\])#', str_replace('\\','/',$file));
            if($isFullPath){
                $file = preg_replace('/[^a-zA-Z0-9._\-\/]/', '', $fileRaw);
                if($file === '' || in_array(basename($file), ['', '.', '..'], true)){
                    throw new \Exception('не указано имя файла (параметр file)');
                }
            }else{
                $file = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($fileRaw));
                if($file === '' || $file === '.' || $file === '..'){
                    throw new \Exception('не указано имя файла (параметр file)');
                }
            }
            $sizeMb = min(512, max(1, $sizeMb));
            $sizeBytes = $sizeMb * 1024 * 1024;

            //валидация уровня PSR-лога
            $allowedLevels = [
                'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
            ];
            $level = strtolower(trim($level));
            if($level === '') $level = 'debug';
            if(!in_array($level, $allowedLevels, true)){
                throw new \Exception('недопустимый уровень лога (параметр level): '.$level.'. Допустимо: '.implode(', ', $allowedLevels));
            }

            //доп. параметры системного лога (JSON-строка или массив)
            $extra = [];
            if(is_string($params) && trim($params) !== ''){
                $decoded = json_decode($params, true);
                if(!is_array($decoded)){
                    throw new \Exception('некорректный JSON в параметре params (ожидается объект вида {"log_size":1000000})');
                }
                $extra = $decoded;
            }elseif(is_array($params)){
                $extra = $params;
            }

            //тип лога: как в log_get — exception_handling или имя PSR-логгера
            $type = trim($type);
            if($type === '') $type = 'exception_handling';
            if($type !== 'exception_handling'){
                $type = preg_replace('/[^0-9a-zA-Z._-]/', '', $type);
                if($type === ''){
                    throw new \Exception('недопустимое имя логгера (параметр type)');
                }
            }

            //каталог размещения лог-файла (только для относительного file): константа AWZ_BXORM_LOG_PATH
            //имеет приоритет, затем существующий /local/logs/, затем /local/php_interface/, иначе /bitrix/php_interface/
            $docRoot = \Bitrix\Main\Application::getDocumentRoot();
            if($isFullPath){
                //полный путь передан в file — используем как есть
                $relative = $file;
            }elseif(defined('AWZ_BXORM_LOG_PATH') && is_string(AWZ_BXORM_LOG_PATH) && AWZ_BXORM_LOG_PATH !== ''){
                $dir = rtrim(str_replace('\\','/', AWZ_BXORM_LOG_PATH), '/');
                $relative = preg_match('#^[/\\\\]#', $dir) ? ltrim($dir, '/') : $dir;
            }elseif(is_dir($docRoot.'/local/logs')){
                $relative = 'local/logs';
            }elseif(is_dir($docRoot.'/local/php_interface')){
                $relative = 'local/php_interface';
            }else{
                $relative = 'bitrix/php_interface';
            }

            $configuration = \Bitrix\Main\Config\Configuration::getInstance();

            //файл должен определиться к этому моменту — иначе молча сохранится битая секция
            if(trim($file) === ''){
                throw new \Exception('не удалось определить имя файла (параметр file потерян при разборе запроса): file="'.(string)$file
                    .'", request.file="'.(string)($reqGet('file') ?? 'null')
                    .'", params_keys='.(is_array($params) ? implode(',', array_keys($params)) : gettype($params))
                    .' — конфиг не изменен');
            }

            //.settings.php должен быть доступен на запись ДО изменения конфига в памяти
            $settingsPath = $docRoot.'/bitrix/.settings.php';
            if(!is_file($settingsPath)){
                $settingsPath = $docRoot.'/.settings.php';
            }
            if(!is_file($settingsPath)){
                throw new \Exception('не найден .settings.php (ожидали bitrix/.settings.php)');
            }
            if(!is_writable($settingsPath)){
                throw new \Exception('файл настроек недоступен для записи: '.$settingsPath.' — конфиг не изменен, проверьте права');
            }

            if($isFullPath){
                //полный путь уже содержит имя файла
                $logPath = $file;
            }else{
                $logPath = (preg_match('#^[/\\\\]#', $relative) ? '' : $docRoot.'/').$relative.'/'.$file;
            }

            $texts = [];

            //exception_handling — стандартный лог: настраивается секция log
            if($type === 'exception_handling'){
                $logConfig = (array)($configuration->get('exception_handling') ?? []);
                //'log' может оказаться NULL/не-массивом (битое состояние в .settings.php) — нормализуем
                if(!isset($logConfig['log']) || !is_array($logConfig['log'])){
                    $logConfig['log'] = [];
                }
                //ЕДИНСТВЕННЫЙ источник истины — локальный $settings; в $logConfig записываем один раз в конце
                $settings = (array)($logConfig['log']['settings'] ?? []);

                //базовые значения из параметров log_set.
                //FileExceptionHandlerLog читает file + log_size (байты) — см. main/lib/diag/fileexceptionhandlerlog.php
                //путь храним ОТНОСИТЕЛЬНЫМ (от DOCUMENT_ROOT): FileExceptionHandlerLog сам приклеивает docRoot
                $fileSetting = $isFullPath ? $file : $relative.'/'.$file;
                if(preg_match('#^([/\\\\]|[a-zA-Z]:[/\\\\])#', $fileSetting)){
                    $docRootNorm = rtrim(str_replace('\\', '/', $docRoot), '/');
                    $fileNorm = str_replace('\\', '/', $fileSetting);
                    if($docRootNorm !== '' && stripos($fileNorm, $docRootNorm.'/') === 0){
                        $fileSetting = ltrim(substr($fileNorm, strlen($docRootNorm)), '/');
                    }
                    //вне DOCUMENT_ROOT относительным быть не может — останется абсолютным
                }
                $settings['file'] = $fileSetting;
                $settings['log_size'] = $sizeBytes;

                //файл должен определиться — иначе сохраним битую секцию с пустым file
                if(trim((string)$settings['file']) === '' || basename((string)$settings['file']) === ''){
                    throw new \Exception('не удалось определить имя файла: file_in="'.(string)$file
                        .'", file_raw="'.(string)$fileRaw.'", relative="'.(string)$relative.'", is_full='.($isFullPath?1:0)
                        .', doc_root="'.(string)$docRoot.'" — конфиг не изменен');
                }

                //доп. параметры ПЕРЕКРЫВАЮТ базовые: известные ключи settings (log_size и т.д.) —
                //в log/settings, class_name — в log/class_name, остальные — на верхний уровень $logConfig
                $allowedExtraSettings = [
                    'log_size', 'log_error', 'log_file_size', 'date_format', 'trace_format', 'format', 'file'
                ];
                $extraApplied = [];
                $applyToSettings = function($key, $val) use (&$settings, &$extraApplied){
                    if(is_array($val)){
                        $appliedVal = array_map('strval', $val);
                    }elseif(is_scalar($val) || $val === null){
                        $appliedVal = $val;
                    }else{
                        return;
                    }
                    $settings[$key] = $appliedVal;
                    $extraApplied[] = $prefix.$key.'='.(is_array($appliedVal) ? json_encode($appliedVal, JSON_UNESCAPED_UNICODE) : (string)$appliedVal);
                };
                foreach($extra as $key=>$val){
                    //служебные ключи секции не перезаписываем через params (иначе можно уронить конфиг, например 'log' => null)
                    if($key === 'class_name' || $key === 'settings' || $key === 'log' || $key === 'debug'
                        || $key === 'name' || $key === 'arguments') continue;
                    if(in_array($key, $allowedExtraSettings, true)){
                        $applyToSettings($key, $val);
                    }elseif(is_scalar($val) || $val === null || is_array($val)){
                        $logConfig[$key] = $val;
                        $extraApplied[] = $key.'='.(is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val);
                    }
                }
                //поддержка вложенной формы: params={"settings":{...}} — всё содержимое уходит в log/settings
                if(isset($extra['settings']) && is_array($extra['settings'])){
                    foreach($extra['settings'] as $key=>$val){
                        $applyToSettings($key, $val);
                    }
                }

                //класс обработчика лога (class_name секции log) — без проверки существования:
                //файл может быть еще не подключен (autoload Bitrix подключит при создании обработчика)
                $className = '';
                if(isset($extra['class_name']) && is_string($extra['class_name']) && trim($extra['class_name']) !== ''){
                    $className = trim($extra['class_name']);
                    if(strpos($className, '\\') !== 0){
                        $className = '\\'.$className;
                    }
                }

                //мусорный верхнеуровневый 'settings' (мог остаться от прошлых версий/кривых params) — убираем,
                //в exception_handling такого ключа быть не должно
                if(isset($logConfig['settings'])){
                    unset($logConfig['settings']);
                }
                $logConfig['log']['settings'] = $settings;
                if($className !== ''){
                    $logConfig['log']['class_name'] = $className;
                }elseif(!isset($logConfig['log']['class_name'])){
                    $logConfig['log']['class_name'] = '\\Bitrix\\Main\\Diag\\FileExceptionHandlerLog';
                }
                $logConfig['debug'] = true;
                $configuration->add('exception_handling', $logConfig);
                $texts[] = 'Стандартный лог настроен: '.$settings['file']
                    .' (создастся при первой записи), максимальный размер до ротации: '.$sizeMb.' МБ ('.$sizeBytes.' байт)'
                    .', log_size: '.$sizeBytes
                    .', class_name: '.$logConfig['log']['class_name']
                    .(($extraApplied) ? ', доп. параметры: '.implode(', ', $extraApplied) : '')
                    .' [diag: file_in="'.(string)$fileRaw.'", file="'.(string)$file.'", relative="'.(string)$relative
                    .'", is_full='.($isFullPath?1:0).', doc_root="'.(string)$docRoot.'", size_mb_in="'.(string)$size_mb
                    .'", params_keys='.(is_array($params) ? implode(',', array_keys($params)) : gettype($params)).']'
                    .'. Читать: log_get с type=exception_handling. Отключить: log_off с type=exception_handling.';
            }else{
                //PSR-лог: имя логгера = type, регистрируется в секции loggers
                //каталог должен существовать/создаваться ДО записи конфига:
                //FileLogger при инициализации с несуществующим каталогом роняет весь сайт (500)
                $logDir = dirname($logPath);
                if(!is_dir($logDir)){
                    if(!@mkdir($logDir, BX_DIR_PERMISSIONS ?? 0755, true) && !is_dir($logDir)){
                        throw new \Exception('каталог лог-файла не существует и не может быть создан: '.$logDir
                            .'. Создайте каталог вручную или укажите существующий файл — конфиг не изменен');
                    }
                }
                if(!is_writable($logDir)){
                    throw new \Exception('каталог лог-файла недоступен для записи: '.$logDir.' — конфиг не изменен');
                }
                $loggersConfig = (array)($configuration->get('loggers') ?? []);
                $loggers = (array)($loggersConfig['value'] ?? $loggersConfig);
                $loggers[$type] = [
                    'className' => '\\Bitrix\\Main\\Diag\\FileLogger',
                    //constructorParams — массив аргументов конструктора FileLogger [файл, макс. размер].
                    //Closure нельзя: она не сериализуется в .settings.php (saveConfiguration использует var_export) и роняет сайт
                    'constructorParams' => [$logPath, $sizeBytes],
                    'level' => constant('\\Psr\\Log\\LogLevel::'.strtoupper($level)),
                ];
                //структура секции loggers: ['loggers']['value'][<имя>] — add() сам оборачивает в 'value',
                //поэтому передаем карту логгеров напрямую (['value'=>...] давал двойную вложенность value.value)
                $configuration->add('loggers', $loggers);
                $texts[] = 'PSR-логгер «'.$type.'» зарегистрирован (loggers): \\Bitrix\\Main\\Diag\\FileLogger, файл '.$logPath.', максимальный размер до ротации: '.$sizeMb.' МБ ('.$sizeBytes.' байт), уровень '.$level
                    .' [diag: file_in="'.(string)$fileRaw.'", log_path="'.$logPath.'", doc_root="'.(string)$docRoot.'"]'
                    .'. Читать: log_get с type='.$type.'. Отключить: log_off с type='.$type.'.';
            }

            //чужие логгеры с Closure в constructorParams (добавленные другими модулями в рантайме)
            //приводим к сериализуемому виду ДО var_export
            $droppedLoggers = $this->sanitizeLoggersForSave();
            if($droppedLoggers){
                $texts[] = 'Внимание: из .settings.php удалены логгеры с несериализуемыми constructorParams (Closure): '.implode(', ', $droppedLoggers)
                    .'. Их конфиг определяется в рантайме и не может быть сохранен в файл.';
            }

            $configuration->saveConfiguration();

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => implode("\n", $texts)
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

            if(is_dir($real)){
                return ['content'=>[['type'=>'text','text'=>'указан каталог, чтение содержимого каталога не поддерживается (только файлы): '.$real]], 'isError'=>true];
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
                if($lines === ''){
                    //без указания строк читаем ХВОСТ (для растущих логов начало неактуально)
                    fseek($fp, -$maxSize, SEEK_END);
                    $content = (string)fread($fp, $maxSize);
                    $truncated = true;
                }else{
                    //с указанием строк читаем начало — нумерация строк должна совпадать с файлом
                    $content = (string)fread($fp, $maxSize);
                    $truncated = true;
                }
                fclose($fp);
            }else{
                $content = (string)file_get_contents($real);
            }

            $rows = explode("\n", $content);
            $total = count($rows);
            $out = [];

            $ranges = $this->parseLineRanges($lines);
            if($ranges === null){
                //весь файл: при хвостовом чтении — последние строки без нумерации (номера не совпали бы с файлом)
                $maxRows = 1000;
                if($truncated){
                    //первый фрагмент хвоста может быть обрезанной строкой — отбрасываем его
                    if(count($rows) > 1 && $rows[0] !== ''){
                        array_shift($rows);
                        $total = count($rows);
                    }
                    $show = array_slice($rows, -$maxRows);
                    foreach($show as $line){
                        $out[] = $line;
                    }
                    $out[] = '... (файл больше '.ceil($maxSize/1024).' КБ, показан хвост ~'.strlen($content).' байт, последние '.count($show).' строк; начало файла пропущено — используйте параметр lines для нумерованного чтения с начала)';
                }else{
                    $show = array_slice($rows, 0, $maxRows);
                    foreach($show as $i=>$line){
                        $out[] = ($i+1).': '.$line;
                    }
                    if($total > $maxRows){
                        $out[] = '... (показаны первые '.$maxRows.' строк, всего '.$total.')';
                    }
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
            'log_get' => 'Возвращает содержимое лог-файла Битрикса: type=exception_handling (по умолчанию) — стандартный лог из .settings.php секции log; type=имя PSR-логгера (например awz.bxorm.App) — файл этого логгера из секции loggers. Последние N строк.',
            'log_set' => 'Включает и настраивает лог Битрикса. type=exception_handling (по умолчанию) — системный лог (секция log в exception_handling .settings.php, по умолчанию класс \Bitrix\Main\Diag\FileExceptionHandlerLog); type=имя PSR-логгера — PSR-лог (создается/обновляется логгер в секции loggers, class \Bitrix\Main\Diag\FileLogger). params — доп. параметры системного лога (JSON или массив): class_name (произвольный класс обработчика, без проверки существования) — в log/class_name; ключи settings (log_size, log_error, log_file_size, date_format, trace_format, format, file) — в log/settings; любые другие ключи — на верхний уровень exception_handling. Каталог файла выбирается автоматически: константа AWZ_BXORM_LOG_PATH, затем существующий /local/logs/, затем /local/php_interface/, иначе /bitrix/php_interface/. В file также можно указать полный путь (например /var/www/site/bitrix/php_interface/exceptions.log) — тогда он используется как есть и записывается в settings.file полностью. Отключение — инструмент log_off.',
            'log_off' => 'Полностью отключает системное логирование и debug. type=exception_handling (по умолчанию) — в .settings.php ставится exception_handling.debug=false и удаляется секция log; type=имя PSR-логгера — логгер удаляется из секции loggers.',
            'log_clear' => 'Усекает (ротирует) лог-файл: содержимое обнуляется, настройки логирования сохраняются и продолжают работать. Файл берется из настроек (type=exception_handling — секция log .settings.php; type=имя PSR-логгера — файл логгера) или задается явно параметром file (те же ограничения, что у file_get).',
            'file_get' => 'Возвращает содержимое файла (путь абсолютный или от корня сайта) — целиком или выбранные строки. Используй, чтобы прочитать файл, на который указала ошибка в логе.'
        ];

        $schemas = [
            'log_get' => [
                'properties' => [
                    'type' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Тип лога: exception_handling (по умолчанию) — файл из .settings.php секции log; либо имя PSR-логгера из секции loggers (например awz.bxorm.App) — его файл читается.'
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
                        description: 'Имя лог-файла (например mcp.log) либо полный путь (например /var/www/site/bitrix/php_interface/exceptions.log — используется как есть). Для имени каталог выбирается автоматически: константа AWZ_BXORM_LOG_PATH, затем существующий /local/logs/, затем /local/php_interface/, иначе /bitrix/php_interface/. Обязательно.'
                    ),
                    'size_mb' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Максимальный размер файла в мегабайтах до ротации, по умолчанию 20, диапазон 1-512'
                    ),
                    'level' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Уровень PSR-лога: debug (по умолчанию), info, notice, warning, error, critical, alert, emergency'
                    ),
                    'type' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Тип лога, как в log_get: exception_handling (по умолчанию) — стандартный лог (секция log в .settings.php); либо имя PSR-логгера (например awz.allxls) — PSR-лог, создается/обновляется логгер с этим именем в секции loggers'
                    ),
                    'params' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Доп. параметры системного лога — JSON-строка или объект (только для type=exception_handling). Допустимы: class_name — произвольный класс обработчика лога (без проверки существования, по умолчанию \Bitrix\Main\Diag\FileExceptionHandlerLog) — попадает в log/class_name; ключи settings: log_size, log_error, log_file_size, date_format, trace_format, format, file — попадают в log/settings; любые другие ключи — на верхний уровень exception_handling. Пример: {"log_size":1000000}'
                    ),
                ],
                'required' => ['file']
            ],
            'log_off' => [
                'properties' => [
                    'type' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Тип лога, как в log_get: exception_handling (по умолчанию) — в .settings.php ставится debug=false и удаляется секция log; либо имя PSR-логгера — логгер удаляется из секции loggers'
                    ),
                ],
                'required' => []
            ],
            'log_clear' => [
                'properties' => [
                    'type' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Тип лога, как в log_get: exception_handling (по умолчанию) — усекается файл из секции log .settings.php; либо имя PSR-логгера — усекается его файл'
                    ),
                    'file' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Необязательный явный путь к файлу (абсолютный или от корня сайта) — усекается он; при пустом значении берется файл из настроек по type'
                    ),
                ],
                'required' => []
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