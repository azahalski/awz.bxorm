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
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;

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
        $shellAllowed = function_exists('shell_exec')
            && stripos((string)ini_get('disable_functions'), 'shell_exec') === false;

        $output1 = null; $output2 = null; $output3 = null;
        if($shellAllowed){
            $output1 = @shell_exec('top -b -n 1');
            $output2 = @shell_exec('free -m');
            $output3 = @shell_exec('nproc');
        }

        if($output1 !== null || $output2 !== null || $output3 !== null){
            $content = [
                ["type"=>"text","text"=>'вывод команды top: '.$output1],
                ["type"=>"text","text"=>'вывод команды free: '.$output2],
                ["type"=>"text","text"=>'nproc: '.$output3],
            ];
            return ['content'=>$content];
        }

        //shell_exec недоступен (disabled_functions / Windows / пустой вывод) — fallback без shell
        $lines = ['shell_exec недоступен — данные через sys_getloadavg + /proc/meminfo'];

        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if(is_array($load) && count($load) >= 3){
            $lines[] = 'load average (1/5/15 мин): '.implode(', ', array_map(
                function($v){ return round((float)$v, 2); }, array_slice($load, 0, 3)
            ));
        }else{
            $lines[] = 'load average: sys_getloadavg недоступен на этой системе';
        }

        $cores = 0;
        if(is_file('/proc/cpuinfo')){
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if($cpuinfo !== false){
                $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
            }
        }
        $lines[] = 'Ядер CPU: '.($cores > 0 ? $cores : 'неизвестно (/proc/cpuinfo недоступен)');

        if(is_file('/proc/meminfo')){
            $meminfo = @file_get_contents('/proc/meminfo');
            $vals = [];
            if($meminfo !== false){
                foreach(explode("\n", $meminfo) as $row){
                    if(preg_match('/^(MemTotal|MemFree|MemAvailable|Buffers|Cached|SwapTotal|SwapFree):\s+(\d+)\s+kB/i', $row, $m)){
                        $vals[$m[1]] = (int)$m[2];
                    }
                }
            }
            if(isset($vals['MemTotal'])){
                $avail = $vals['MemAvailable'] ?? (($vals['MemFree'] ?? 0) + ($vals['Buffers'] ?? 0) + ($vals['Cached'] ?? 0));
                $used = max(0, $vals['MemTotal'] - $avail);
                $lines[] = 'Память всего: '.round($vals['MemTotal']/1024).' МБ';
                $lines[] = 'Память занято: '.round($used/1024).' МБ ('.round($used*100/max(1, $vals['MemTotal'])).'%)';
                $lines[] = 'Память доступно: '.round($avail/1024).' МБ';
                if(($vals['SwapTotal'] ?? 0) > 0){
                    $swapUsed = max(0, $vals['SwapTotal'] - ($vals['SwapFree'] ?? 0));
                    $lines[] = 'Swap всего: '.round($vals['SwapTotal']/1024).' МБ, занято: '.round($swapUsed/1024).' МБ';
                }
            }else{
                $lines[] = '/proc/meminfo не удалось разобрать';
            }
        }else{
            $lines[] = '/proc/meminfo недоступен (не Linux)';
        }

        return ['content'=>[["type"=>"text","text"=>implode("\n", $lines)]]];
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

    /**
     * Допустимые расширения изображений (base64-загрузка)
     */
    const PHOTO_ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];

    /**
     * MCP-инструмент iblocks: список инфоблоков сайта (для выбора IBLOCK_ID).
     * name — подстрока поиска по названию, type — IBLOCK_TYPE_ID,
     * active_only — Y (по умолчанию) / N.
     */
    public function iblocksAction(string $name = '', string $type = '', string $activeOnly = 'Y')
    {
        try{
            $name = trim((string)$name);
            $type = trim((string)$type);

            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }

            $arParams = ['CHECK_PERMISSIONS' => 'N'];
            if($activeOnly !== 'N'){
                $arParams['ACTIVE'] = 'Y';
            }
            if($type !== ''){
                $arParams['IBLOCK_TYPE_ID'] = $type;
            }
            if($name !== ''){
                $arParams['?NAME'] = $name;
            }

            $items = [];
            $res = \CIBlock::GetList(
                ['SORT' => 'ASC', 'NAME' => 'ASC'],
                $arParams,
                true
            );
            while($row = $res->Fetch()){
                $items[] = [
                    'ID' => (int)$row['ID'],
                    'NAME' => (string)$row['NAME'],
                    'CODE' => (string)($row['CODE'] ?? ''),
                    'IBLOCK_TYPE_ID' => (string)$row['IBLOCK_TYPE_ID'],
                    'ACTIVE' => (string)$row['ACTIVE'],
                    'LID' => (string)($row['LID'] ?? ''),
                    'ELEMENTS_CNT' => (int)($row['ELEMENT_CNT'] ?? 0),
                ];
            }

            if(empty($items)){
                return ['content'=>[['type'=>'text','text'=>'Инфоблоки не найдены (фильтр: name="'.$name.'", type="'.$type.'")']]];
            }

            $out = ['Найдено инфоблоков: '.count($items)];
            foreach($items as $item){
                $out[] = '[#'.$item['ID'].'] '.$item['NAME']
                    .' | код: '.($item['CODE'] !== '' ? $item['CODE'] : '—')
                    .' | тип: '.$item['IBLOCK_TYPE_ID']
                    .' | активен: '.$item['ACTIVE']
                    .' | сайт: '.($item['LID'] !== '' ? $item['LID'] : '—')
                    .' | элементов: '.$item['ELEMENTS_CNT'];
            }

            return ['content'=>[['type'=>'text','text'=>implode("\n", $out)]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool iblocks error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * MCP-инструмент sections: список активных разделов инфоблока каталога.
     */
    public function sectionsAction(string $iblock_id){
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $iblockId = (int)$iblock_id;
            if(!$iblockId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор инфоблока (параметр iblock_id)']], 'isError'=>true];
            }
            $arFilter = [
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ];
            $arSections = [];
            $res = \CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC'],
                $arFilter,
                false,
                ['ID','NAME','IBLOCK_SECTION_ID','CODE','SECTION_PAGE_URL','DEPTH_LEVEL']
            );
            while($data = $res->fetch()){
                $arSections[] = [
                    'ID' => (int)$data['ID'],
                    'NAME' => (string)$data['NAME'],
                    'IBLOCK_SECTION_ID' => (int)($data['IBLOCK_SECTION_ID'] ?? 0),
                    'CODE' => (string)($data['CODE'] ?? ''),
                    'SECTION_PAGE_URL' => (string)($data['SECTION_PAGE_URL'] ?? ''),
                    'DEPTH_LEVEL' => (int)($data['DEPTH_LEVEL'] ?? 0),
                ];
            }

            if(empty($arSections)){
                return ['content'=>[['type'=>'text','text'=>'Разделы не найдены (IBLOCK_ID='.$iblockId.')']]];
            }

            $out = ['Найдено разделов: '.count($arSections)];
            foreach($arSections as $section){
                $out[] = '[#'.$section['ID'].'] '.str_repeat('  ', max(0, $section['DEPTH_LEVEL']-1)).$section['NAME']
                    .' | код: '.($section['CODE'] !== '' ? $section['CODE'] : '—')
                    .' | родитель: '.($section['IBLOCK_SECTION_ID'] ? '#'.$section['IBLOCK_SECTION_ID'] : '—')
                    .' | уровень: '.$section['DEPTH_LEVEL']
                    .' | url: '.($section['SECTION_PAGE_URL'] !== '' ? $section['SECTION_PAGE_URL'] : '—');
            }

            return ['content'=>[['type'=>'text','text'=>implode("\n", $out)]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool sections error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * MCP-инструмент loadphoto: сохраняет постоянное изображение из base64
     * в /upload/awz.load.ai/ и возвращает публичный URL.
     * Форматы: png, jpg, jpeg, webp, svg, gif.
     */
    public function loadphotoAction(string $photo = '')
    {
        try{
            $saved = $this->saveBase64Image((string)$photo, '/upload/awz.load.ai/', false);
            if($saved === null){
                return ['content'=>[['type'=>'text','text'=>'Некорректное изображение или формат не поддерживается. Допустимо: '.implode(', ', self::PHOTO_ALLOWED_EXTENSIONS)]], 'isError'=>true];
            }
            return ['content'=>[['type'=>'text','text'=>'Изображение сохранено: '.$saved]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool loadphoto error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * MCP-инструмент tmpphoto: сохраняет временное изображение из base64
     * в /upload/awz.gen.tmp/ (файлы старше 1 часа удаляются) и возвращает публичный URL.
     * Форматы: png, jpg, jpeg, webp, svg, gif.
     */
    public function tmpphotoAction(string $photo = '')
    {
        try{
            $relativeDir = '/upload/awz.gen.tmp/';
            $tmpDir = Application::getDocumentRoot().$relativeDir;
            if(is_dir($tmpDir)){
                foreach(glob($tmpDir.'*') as $oldFile){
                    if(is_file($oldFile) && filemtime($oldFile) < (time() - 3600)){
                        @unlink($oldFile);
                    }
                }
            }

            $saved = $this->saveBase64Image((string)$photo, $relativeDir, true);
            if($saved === null){
                return ['content'=>[['type'=>'text','text'=>'Некорректное изображение или формат не поддерживается. Допустимо: '.implode(', ', self::PHOTO_ALLOWED_EXTENSIONS)]], 'isError'=>true];
            }
            return ['content'=>[['type'=>'text','text'=>'Временное изображение сохранено: '.$saved]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool tmpphoto error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Декодирует base64 (с отсечением префикса data:image/...;base64,), определяет
     * формат по сигнатуре и сохраняет файл в указанную директорию.
     * Возвращает публичный URL или null при ошибке.
     */
    private function saveBase64Image(string $photo, string $relativeDir, bool $createDir = false): ?string
    {
        if(strpos($photo, ',') !== false){
            @list(, $photo) = explode(',', $photo, 2);
        }
        $photo = trim((string)$photo);
        if($photo === ''){
            return null;
        }
        $decodedData = base64_decode($photo, true);
        if($decodedData === false || $decodedData === ''){
            return null;
        }

        $ext = $this->detectImageExt($decodedData);
        if($ext === '' || !in_array($ext, self::PHOTO_ALLOWED_EXTENSIONS, true)){
            return null;
        }

        $absDir = Application::getDocumentRoot().$relativeDir;
        if($createDir && !Directory::isDirectoryExists($absDir)){
            Directory::createDirectory($absDir);
        }
        if(!is_dir($absDir)){
            return null;
        }

        $fileName = \Bitrix\Main\Security\Random::getString(10).'.'.$ext;
        $file = new File($absDir.$fileName);
        $file->putContents($decodedData);

        return 'https://'.Application::getInstance()->getContext()->getRequest()->getHttpHost()
            .$relativeDir.$fileName;
    }

    /**
     * Определяет расширение изображения по сигнатуре (магическим байтам).
     * jpeg нормализуется в jpg, svg — по наличию тега <svg в начале файла.
     */
    private function detectImageExt(string $data): string
    {
        $head = substr($data, 0, 16);
        if(strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0){
            return 'png';
        }
        if(strncmp($head, "\xFF\xD8\xFF", 3) === 0){
            return 'jpg';
        }
        if(strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0){
            return 'gif';
        }
        if(strncmp($head, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP'){
            return 'webp';
        }
        $trim = ltrim($data, "\xEF\xBB\xBF \t\r\n");
        if(str_starts_with($trim, '<?xml') || str_starts_with($trim, '<svg')){
            if(stripos(substr($trim, 0, 1024), '<svg') !== false){
                return 'svg';
            }
        }
        return '';
    }

    /**
     * Возвращает ID инфоблока по ID элемента (ElementTable).
     */
    private function getElementIblockId(int $elementId): int
    {
        if($elementId <= 0) return 0;
        try{
            $row = \Bitrix\Iblock\ElementTable::getRow([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $elementId]
            ]);
            return $row ? (int)$row['IBLOCK_ID'] : 0;
        }catch(\Throwable $e){
            return 0;
        }
    }

    /**
     * Возвращает элемент по ID (для резолва инфоблока, D7).
     */
    private function getElementRow(int $id): array
    {
        if($id <= 0) return [];
        try{
            $row = \Bitrix\Iblock\ElementTable::getRowById($id);
            return $row ? (array)$row : [];
        }catch(\Throwable $e){
            return [];
        }
    }

    /**
     * Обновляет поисковый и фасетный индекс элемента и пересчитывает
     * наследуемые мета-значения. В параметрах — только ID элемента,
     * ID инфоблока определяется автоматически.
     */
    public function reindexitemAction(string $id)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $elementId = (int)$id;
            if(!$elementId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор элемента (параметр id)']], 'isError'=>true];
            }
            $iblockId = $this->getElementIblockId($elementId);
            if(!$iblockId){
                $elem = $this->getElementRow($elementId);
                $iblockId = $elem ? (int)$elem['IBLOCK_ID'] : 0;
            }
            if(!$iblockId){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден: '.$elementId]], 'isError'=>true];
            }

            \CIBlockElement::UpdateSearch($elementId, true);
            \Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($iblockId, $elementId);
            $ipropValues = new \Bitrix\Iblock\InheritedProperty\ElementValues($iblockId, $elementId);
            $ipropValues->clearValues();

            return ['content'=>[['type'=>'text','text'=>'Обновлены поисковый и фасетный индексы, пересчитаны мета элемента #'.$elementId.' (IBLOCK_ID='.$iblockId.')']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool reindexitem error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Обновляет значение свойства элемента инфоблока по коду свойства.
     */
    public function updateitempropAction(string $id, string $code, $value)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $elementId = (int)$id;
            if(!$elementId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор элемента (параметр id)']], 'isError'=>true];
            }
            $code = trim($code);
            if($code === ''){
                return ['content'=>[['type'=>'text','text'=>'не указан код свойства (параметр code)']], 'isError'=>true];
            }
            $elem = $this->getElementRow($elementId);
            if(!$elem){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден: '.$elementId]], 'isError'=>true];
            }

            \CIBlockElement::SetPropertyValueCode($elementId, $code, $value);

            return ['content'=>[['type'=>'text','text'=>'Свойство '.$code.' элемента #'.$elementId.' обновлено']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool updateitemprop error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Очищает мета-теги элемента (шаблоны наследуемых свойств + кеш значений).
     */
    public function deletemetaitemAction(string $id)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $elementId = (int)$id;
            if(!$elementId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор элемента (параметр id)']], 'isError'=>true];
            }
            $elem = $this->getElementRow($elementId);
            if(!$elem){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден: '.$elementId]], 'isError'=>true];
            }
            $iblockId = (int)$elem['IBLOCK_ID'];

            $seoTemplates = new \Bitrix\Iblock\InheritedProperty\ElementTemplates($iblockId, $elementId);
            $seoTemplates->set([
                'ELEMENT_PAGE_TITLE' => '',
                'ELEMENT_META_TITLE' => '',
                'ELEMENT_META_DESCRIPTION' => '',
            ]);

            $ipropValues = new \Bitrix\Iblock\InheritedProperty\ElementValues($iblockId, $elementId);
            $ipropValues->clearValues();

            return ['content'=>[['type'=>'text','text'=>'Мета-теги элемента #'.$elementId.' очищены']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool deletemetaitem error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Очищает мета-теги раздела (шаблоны наследуемых свойств + кеш значений).
     */
    public function deletemetasectionAction(string $id)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $sectionId = (int)$id;
            if(!$sectionId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор раздела (параметр id)']], 'isError'=>true];
            }
            $section = \CIBlockSection::GetByID($sectionId)->GetNext();
            if(!$section){
                return ['content'=>[['type'=>'text','text'=>'Раздел не найден: '.$sectionId]], 'isError'=>true];
            }
            $iblockId = (int)$section['IBLOCK_ID'];

            $seoTemplates = new \Bitrix\Iblock\InheritedProperty\SectionTemplates($iblockId, $sectionId);
            $seoTemplates->set([
                'SECTION_PAGE_TITLE' => '',
                'SECTION_META_TITLE' => '',
                'SECTION_META_DESCRIPTION' => '',
            ]);

            $ipropValues = new \Bitrix\Iblock\InheritedProperty\SectionValues($iblockId, $sectionId);
            $ipropValues->clearValues();

            return ['content'=>[['type'=>'text','text'=>'Мета-теги раздела #'.$sectionId.' очищены']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool deletemetasection error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Устанавливает мета-теги элемента (H1, title, description).
     * Пустые параметры не перезаписывают текущие значения.
     */
    public function updateseoitemAction(string $id, string $page_title = '', string $seo_title = '', string $seo_descr = '')
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $elementId = (int)$id;
            if(!$elementId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор элемента (параметр id)']], 'isError'=>true];
            }
            $elem = $this->getElementRow($elementId);
            if(!$elem){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден: '.$elementId]], 'isError'=>true];
            }
            $iblockId = (int)$elem['IBLOCK_ID'];

            $el = new \CIBlockElement();
            $el->Update($elementId, []);

            $seoFields = [];
            if($page_title !== ''){
                $seoFields['ELEMENT_PAGE_TITLE'] = $page_title;
            }
            if($seo_title !== ''){
                $seoFields['ELEMENT_META_TITLE'] = $seo_title;
            }
            if($seo_descr !== ''){
                $seoFields['ELEMENT_META_DESCRIPTION'] = $seo_descr;
            }
            if(!empty($seoFields)){
                $seoTemplates = new \Bitrix\Iblock\InheritedProperty\ElementTemplates($iblockId, $elementId);
                $seoTemplates->set($seoFields);
            }

            $ipropValues = new \Bitrix\Iblock\InheritedProperty\ElementValues($iblockId, $elementId);
            $ipropValues->clearValues();

            return ['content'=>[['type'=>'text','text'=>'Мета-теги элемента #'.$elementId.' обновлены'.(!empty($seoFields) ? ': '.implode(', ', array_keys($seoFields)) : '')]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool updateitem error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Устанавливает мета-теги раздела (H1, title, description).
     * Пустые параметры не перезаписывают текущие значения.
     */
    public function updateseosectAction(string $id, string $page_title = '', string $seo_title = '', string $seo_descr = '')
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $sectionId = (int)$id;
            if(!$sectionId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор раздела (параметр id)']], 'isError'=>true];
            }
            $section = \CIBlockSection::GetByID($sectionId)->GetNext();
            if(!$section){
                return ['content'=>[['type'=>'text','text'=>'Раздел не найден: '.$sectionId]], 'isError'=>true];
            }
            $iblockId = (int)$section['IBLOCK_ID'];

            $seoFields = [];
            if($page_title !== ''){
                $seoFields['SECTION_PAGE_TITLE'] = $page_title;
            }
            if($seo_title !== ''){
                $seoFields['SECTION_META_TITLE'] = $seo_title;
            }
            if($seo_descr !== ''){
                $seoFields['SECTION_META_DESCRIPTION'] = $seo_descr;
            }
            if(!empty($seoFields)){
                $seoTemplates = new \Bitrix\Iblock\InheritedProperty\SectionTemplates($iblockId, $sectionId);
                $seoTemplates->set($seoFields);
            }

            $ipropValues = new \Bitrix\Iblock\InheritedProperty\SectionValues($iblockId, $sectionId);
            $ipropValues->clearValues();

            return ['content'=>[['type'=>'text','text'=>'Мета-теги раздела #'.$sectionId.' обновлены'.(!empty($seoFields) ? ': '.implode(', ', array_keys($seoFields)) : '')]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool updatesect error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Возвращает данные элемента инфоблока по ID или фильтру (JSON):
     * поля элемента + непустые свойства (CODE, NAME, VALUE).
     */
    public function getitemAction(string $id = '', string $filter = '', int $limit = 1)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $limit = max(1, min(100, (int)$limit));
            $arParams = ['CHECK_PERMISSIONS' => 'N'];
            $filter = trim($filter);
            if($filter !== ''){
                $decoded = json_decode($filter, true);
                if(!is_array($decoded)){
                    return ['content'=>[['type'=>'text','text'=>'некорректный JSON в параметре filter (ожидается объект вида {"CODE":"value"})']], 'isError'=>true];
                }
                foreach($decoded as $key=>$val){
                    $arParams[(string)$key] = $val;
                }
            }
            $id = trim($id);
            if($id !== ''){
                $arParams['ID'] = (int)$id;
            }
            if(!isset($arParams['ID']) && count($arParams) <= 1){
                return ['content'=>[['type'=>'text','text'=>'укажите id элемента или filter (JSON)']], 'isError'=>true];
            }

            $resOb = \CIBlockElement::GetList([], $arParams, false, ['nTopCount' => $limit]);
            $items = [];
            while($resOb && ($res = $resOb->GetNextElement())){
                $item = $res->GetFields();
                $item['PROPERTIES'] = [];
                $props = $res->GetProperties();
                foreach($props as $propCode=>$prop){
                    if(empty($prop['VALUE'])) continue;
                    $item['PROPERTIES'][$propCode] = [
                        'CODE'=>$propCode,
                        'NAME'=>$prop['NAME'],
                        'TYPE'=>$prop['PROPERTY_TYPE'],
                        'VALUE'=>$prop['VALUE'],
                    ];
                }
                $items[] = $item;
            }

            if(empty($items)){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден (id="'.$id.'", filter='.$filter.')']], 'isError'=>true];
            }

            $text = ($limit > 1) ? json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                : json_encode($items[0], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            return ['content'=>[['type'=>'text','text'=>$text]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool getitem error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Подготовка файлового поля (фото): путь/URL -> CFile::MakeFileArray.
     * Пустое значение — не трогаем.
     */
    private function preparePhotoValue($value)
    {
        if(is_array($value) || $value === null){
            return $value;
        }
        $value = trim((string)$value);
        if($value === ''){
            return null;
        }
        if($value === 'false' || $value === 'del'){
            return ['del' => 'Y'];
        }
        return \CFile::MakeFileArray($value);
    }

    /**
     * Сборка полей элемента из общих параметров (additem/updateitemfields).
     * fields — доп. поля JSON-строкой или массивом.
     * Возвращает [fields, error].
     */
    private function buildElementFields($iblock_id, string $name, string $description,
        $section_id, $preview_picture, $detail_picture, string $active, $fields): array
    {
        $iblockId = (int)$iblock_id;
        if($iblockId <= 0){
            return [[], 'не указан идентификатор инфоблока (параметр iblock_id)'];
        }

        $arFields = ['IBLOCK_ID' => $iblockId];
        if(trim($name) !== ''){
            $arFields['NAME'] = trim($name);
        }
        if(trim($description) !== ''){
            $arFields['DETAIL_TEXT'] = $description;
            $arFields['DETAIL_TEXT_TYPE'] = preg_match('/<[^>]+>/', $description) ? 'html' : 'text';
        }
        $sectionId = (int)$section_id;
        if($sectionId > 0){
            $arFields['IBLOCK_SECTION'] = $sectionId;
        }
        $photo = $this->preparePhotoValue($preview_picture);
        if($photo !== null){
            $arFields['PREVIEW_PICTURE'] = $photo;
        }
        $photo = $this->preparePhotoValue($detail_picture);
        if($photo !== null){
            $arFields['DETAIL_PICTURE'] = $photo;
        }
        $active = strtoupper(trim($active));
        if($active !== ''){
            $arFields['ACTIVE'] = ($active === 'N') ? 'N' : 'Y';
        }else{
            $arFields['ACTIVE'] = 'Y';
        }

        if(is_string($fields) && trim($fields) !== ''){
            $decoded = json_decode($fields, true);
            if(!is_array($decoded)){
                return [[], 'некорректный JSON в параметре fields (ожидается объект вида {"CODE":"value"})'];
            }
            $fields = $decoded;
        }
        if(is_array($fields)){
            foreach($fields as $key=>$val){
                $key = (string)$key;
                if($key === '' || in_array($key, ['IBLOCK_ID', 'ID', 'NAME', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
                    'IBLOCK_SECTION', 'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'ACTIVE'], true)){
                    continue;
                }
                if(str_starts_with($key, 'PROPERTY_')){
                    $arFields[$key] = $val;
                }else{
                    $arFields[strtoupper($key)] = $val;
                }
            }
        }

        return [$arFields, ''];
    }

    /**
     * Генерирует уникальный символьный код на основе названия элемента
     * в пределах инфоблока. Если код занят, добавляет суффикс (-2, -3, ...).
     */
    private function generateUniqueElementCode(int $iblockId, string $name): string
    {
        $base = \CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
        ]);
        $base = trim($base, '-');
        if($base === ''){
            $base = 'element';
        }

        try{
            $code = $base;
            $suffix = 2;
            while(
                \Bitrix\Iblock\ElementTable::getRow([
                    'select' => ['ID'],
                    'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
                    'limit' => 1,
                ])
            ){
                $code = $base.'-'.$suffix;
                $suffix++;
            }
            return $code;
        }catch(\Throwable $e){
            return $base.'-'.substr((string)time(), -6);
        }
    }

    /**
     * Добавляет элемент инфоблока: инфоблок, название, описание (детальное),
     * раздел, фото анонса/детальное (путь/URL), активность (по умолчанию Y),
     * доп. поля в fields (JSON, в т.ч. PROPERTY_<КОД>).
     */
    public function additemAction($iblock_id, string $name = '', string $description = '',
        $section_id = 0, $preview_picture = '', $detail_picture = '', string $active = 'Y', $fields = [])
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            [$arFields, $error] = $this->buildElementFields($iblock_id, $name, $description,
                $section_id, $preview_picture, $detail_picture, $active, $fields);
            if($error !== ''){
                return ['content'=>[['type'=>'text','text'=>$error]], 'isError'=>true];
            }
            if(trim($arFields['NAME'] ?? '') === ''){
                return ['content'=>[['type'=>'text','text'=>'не указано название элемента (параметр name)']], 'isError'=>true];
            }
            //символьный код: если не передан в fields — генерируется
            //уникальный в пределах инфоблока из названия
            $arFields['CODE'] = (string)($arFields['CODE'] ?? '');
            if(trim($arFields['CODE']) === ''){
                $arFields['CODE'] = $this->generateUniqueElementCode((int)$iblock_id, (string)$arFields['NAME']);
            }

            $el = new \CIBlockElement();
            $newId = $el->Add($arFields, false, true, true);
            if(!$newId){
                return ['content'=>[['type'=>'text','text'=>'Ошибка добавления элемента: '.$el->LAST_ERROR]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Элемент добавлен: ID='.(int)$newId.' (IBLOCK_ID='.(int)$iblock_id.')']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool additem error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Изменяет элемент инфоблока: название, описание (детальное), раздел,
     * фото анонса/детальное (путь/URL; 'false'/'del' — удалить),
     * активность, доп. поля в fields (JSON, в т.ч. PROPERTY_<КОД>).
     * Пустые параметры не перезаписывают текущие значения.
     */
    public function updateitemfieldsAction(string $id, string $name = '', string $description = '',
        $section_id = 0, $preview_picture = '', $detail_picture = '', string $active = '', $fields = [])
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $elementId = (int)$id;
            if(!$elementId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор элемента (параметр id)']], 'isError'=>true];
            }
            $elem = $this->getElementRow($elementId);
            if(!$elem){
                return ['content'=>[['type'=>'text','text'=>'Элемент не найден: '.$elementId]], 'isError'=>true];
            }
            [$arFields, $error] = $this->buildElementFields($elem['IBLOCK_ID'], $name, $description,
                $section_id, $preview_picture, $detail_picture, $active, $fields);
            if($error !== ''){
                return ['content'=>[['type'=>'text','text'=>$error]], 'isError'=>true];
            }
            unset($arFields['IBLOCK_ID'], $arFields['ACTIVE']);
            //активность меняется только если явно передана
            $activeRaw = strtoupper(trim($active));
            if($activeRaw === 'Y' || $activeRaw === 'N'){
                $arFields['ACTIVE'] = $activeRaw;
            }
            if(empty($arFields)){
                return ['content'=>[['type'=>'text','text'=>'Нет данных для обновления (все параметры пусты)']], 'isError'=>true];
            }

            $el = new \CIBlockElement();
            if(!$el->Update($elementId, $arFields, false, true)){
                return ['content'=>[['type'=>'text','text'=>'Ошибка обновления элемента: '.$el->LAST_ERROR]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Элемент #'.$elementId.' обновлен: '.implode(', ', array_keys($arFields))]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool updateitemfields error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Сборка полей раздела (addsection/updatesection).
     * fields — доп. поля JSON-строкой или массивом (UF_<КОД> и прочие).
     * Возвращает [fields, error].
     */
    private function buildSectionFields($iblock_id, string $name, string $description,
        $parent_id, string $code, string $active, $picture, $detail_picture, $fields, bool $forUpdate): array
    {
        $iblockId = (int)$iblock_id;
        if($iblockId <= 0){
            return [[], 'не указан идентификатор инфоблока (параметр iblock_id)'];
        }

        $arFields = ['IBLOCK_ID' => $iblockId];
        if(trim($name) !== ''){
            $arFields['NAME'] = trim($name);
        }
        if(trim($description) !== ''){
            $arFields['DESCRIPTION'] = $description;
            $arFields['DESCRIPTION_TYPE'] = preg_match('/<[^>]+>/', $description) ? 'html' : 'text';
        }
        //parent_id — строка: пустая = не менять, '0' — перенести в корень, 'N' — родитель
        $parentId = trim((string)$parent_id);
        if($parentId !== ''){
            $arFields['IBLOCK_SECTION_ID'] = (int)$parentId;
        }elseif(!$forUpdate){
            $arFields['IBLOCK_SECTION_ID'] = 0;
        }
        $code = trim($code);
        if($code !== ''){
            $arFields['CODE'] = $code;
        }
        $active = strtoupper(trim($active));
        if($active === 'Y' || $active === 'N'){
            $arFields['ACTIVE'] = $active;
        }elseif(!$forUpdate){
            $arFields['ACTIVE'] = 'Y';
        }
        $photo = $this->preparePhotoValue($picture);
        if($photo !== null){
            $arFields['PICTURE'] = $photo;
        }
        $photo = $this->preparePhotoValue($detail_picture);
        if($photo !== null){
            $arFields['DETAIL_PICTURE'] = $photo;
        }

        if(is_string($fields) && trim($fields) !== ''){
            $decoded = json_decode($fields, true);
            if(!is_array($decoded)){
                return [[], 'некорректный JSON в параметре fields (ожидается объект вида {"UF_CODE":"value"})'];
            }
            $fields = $decoded;
        }
        if(is_array($fields)){
            foreach($fields as $key=>$val){
                $key = strtoupper((string)$key);
                if($key === '' || in_array($key, ['IBLOCK_ID', 'ID', 'NAME', 'DESCRIPTION', 'DESCRIPTION_TYPE',
                    'IBLOCK_SECTION_ID', 'CODE', 'ACTIVE', 'PICTURE', 'DETAIL_PICTURE', 'SORT', 'GLOBAL_ACTIVE',
                    'DEPTH_LEVEL', 'LEFT_MARGIN', 'RIGHT_MARGIN', 'TIMESTAMP_X', 'MODIFIED_BY'], true)){
                    continue;
                }
                $arFields[$key] = $val;
            }
        }

        return [$arFields, ''];
    }

    /**
     * Генерирует уникальный символьный код на основе названия раздела
     * в пределах инфоблока. Если код занят, добавляет суффикс (-2, -3, ...).
     */
    private function generateUniqueSectionCode(int $iblockId, string $name): string
    {
        $base = \CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '-',
        ]);
        $base = trim($base, '-');
        if($base === ''){
            $base = 'section';
        }

        try{
            $code = $base;
            $suffix = 2;
            while(
                \Bitrix\Iblock\SectionTable::getRow([
                    'select' => ['ID'],
                    'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
                    'limit' => 1,
                ])
            ){
                $code = $base.'-'.$suffix;
                $suffix++;
            }
            return $code;
        }catch(\Throwable $e){
            return $base.'-'.substr((string)time(), -6);
        }
    }

    /**
     * Добавляет раздел инфоблока: инфоблок, название, описание, родительский раздел,
     * символьный код, активность (по умолчанию Y), картинка (путь/URL),
     * доп. поля в fields (JSON, в т.ч. UF_<КОД>).
     */
    public function addsectionAction($iblock_id, string $name = '', string $description = '',
        $parent_id = 0, string $code = '', string $active = 'Y', $picture = '', $detail_picture = '', $fields = [])
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            [$arFields, $error] = $this->buildSectionFields($iblock_id, $name, $description,
                $parent_id, $code, $active, $picture, $detail_picture, $fields, false);
            if($error !== ''){
                return ['content'=>[['type'=>'text','text'=>$error]], 'isError'=>true];
            }
            if(trim($arFields['NAME'] ?? '') === ''){
                return ['content'=>[['type'=>'text','text'=>'не указано название раздела (параметр name)']], 'isError'=>true];
            }
            //символьный код: если не передан — генерируется
            //уникальный в пределах инфоблока из названия
            $arFields['CODE'] = (string)($arFields['CODE'] ?? '');
            if(trim($arFields['CODE']) === ''){
                $arFields['CODE'] = $this->generateUniqueSectionCode((int)$iblock_id, (string)$arFields['NAME']);
            }

            $bs = new \CIBlockSection();
            $newId = $bs->Add($arFields);
            if(!$newId){
                return ['content'=>[['type'=>'text','text'=>'Ошибка добавления раздела: '.$bs->LAST_ERROR]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Раздел добавлен: ID='.(int)$newId.' (IBLOCK_ID='.(int)$iblock_id.')']]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool addsection error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Изменяет раздел инфоблока: название, описание, родительский раздел,
     * символьный код, активность, картинка (путь/URL; 'false'/'del' — удалить),
     * доп. поля в fields (JSON, в т.ч. UF_<КОД>).
     * Пустые параметры не перезаписывают текущие значения.
     */
    public function updatesectionAction(string $id, string $name = '', string $description = '',
        string $parent_id = '', string $code = '', string $active = '', $picture = '', $detail_picture = '', $fields = [])
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $sectionId = (int)$id;
            if(!$sectionId){
                return ['content'=>[['type'=>'text','text'=>'не указан идентификатор раздела (параметр id)']], 'isError'=>true];
            }
            try{
                $section = \Bitrix\Iblock\SectionTable::getRowById($sectionId);
            }catch(\Throwable $e){
                $section = null;
            }
            if(!$section){
                return ['content'=>[['type'=>'text','text'=>'Раздел не найден: '.$sectionId]], 'isError'=>true];
            }
            [$arFields, $error] = $this->buildSectionFields($section['IBLOCK_ID'], $name, $description,
                $parent_id, $code, $active, $picture, $detail_picture, $fields, true);
            if($error !== ''){
                return ['content'=>[['type'=>'text','text'=>$error]], 'isError'=>true];
            }
            unset($arFields['IBLOCK_ID']);
            if(empty($arFields)){
                return ['content'=>[['type'=>'text','text'=>'Нет данных для обновления (все параметры пусты)']], 'isError'=>true];
            }

            $bs = new \CIBlockSection();
            if(!is_object($bs) || !($bs instanceof \CIBlockSection)){
                return ['content'=>[['type'=>'text','text'=>'Ошибка обновления раздела: не создан объект CIBlockSection']], 'isError'=>true];
            }
            //Update возвращает true при успехе; при ошибке — false, текст ошибки в LAST_ERROR
            if(!$bs->Update($sectionId, $arFields)){
                return ['content'=>[['type'=>'text','text'=>'Ошибка обновления раздела: '.($bs->LAST_ERROR ?: 'неизвестная ошибка Update')]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Раздел #'.$sectionId.' обновлен: '.implode(', ', array_keys($arFields))]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool updatesection error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Возвращает данные раздела инфоблока по ID или фильтру (JSON):
     * все поля + значения UF_* (свойства раздела).
     */
    public function getsectionAction(string $id = '', string $filter = '', int $limit = 1)
    {
        try{
            if(!Loader::includeModule('iblock')){
                return ['content'=>[['type'=>'text','text'=>'Модуль iblock не установлен']], 'isError'=>true];
            }
            $limit = max(1, min(100, (int)$limit));
            $arFilter = [];
            $filter = trim($filter);
            if($filter !== ''){
                $decoded = json_decode($filter, true);
                if(!is_array($decoded)){
                    return ['content'=>[['type'=>'text','text'=>'некорректный JSON в параметре filter (ожидается объект вида {"CODE":"value"})']], 'isError'=>true];
                }
                foreach($decoded as $key=>$val){
                    $arFilter[(string)$key] = $val;
                }
            }
            $id = trim($id);
            if($id !== ''){
                $arFilter['=ID'] = (int)$id;
            }
            if(empty($arFilter)){
                return ['content'=>[['type'=>'text','text'=>'укажите id раздела или filter (JSON)']], 'isError'=>true];
            }

            $rows = [];
            try{
                $res = \Bitrix\Iblock\SectionTable::getList([
                    'select' => ['*', 'UF_*'],
                    'filter' => $arFilter,
                    'limit' => $limit,
                    'order' => ['ID' => 'ASC'],
                ]);
                $rows = $res->fetchAll();
            }catch(\Throwable $e){
                return ['content'=>[['type'=>'text','text'=>'Ошибка запроса разделов: '.$e->getMessage()]], 'isError'=>true];
            }

            if(empty($rows)){
                return ['content'=>[['type'=>'text','text'=>'Раздел не найден (id="'.$id.'", filter='.$filter.')']], 'isError'=>true];
            }

            $text = ($limit > 1) ? json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                : json_encode($rows[0], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            return ['content'=>[['type'=>'text','text'=>$text]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool getsection error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    public function listAction(int $app, $json = []){

        $mcpData = \Awz\BxOrm\HooksTable::getRowById($this->getRequest()->get('app'));

        $descriptions = [
            'envinfo' => 'Возвращает информацию об окружении',
            'phpinfo' => 'Возвращает информацию о php на сайте',
            'cmsinfo' => 'Возвращает информацию о cms и модулях',
            'dbinfo' => 'Возвращает информацию о базе данных',
            'processinfo' => 'Возвращает информацию о нагрузке системы: вывод top/free/nproc через shell_exec, а при недоступном shell — load average через sys_getloadavg, число ядер (/proc/cpuinfo) и память (/proc/meminfo).',
            'log_get' => 'Возвращает содержимое лог-файла Битрикса: type=exception_handling (по умолчанию) — стандартный лог из .settings.php секции log; type=имя PSR-логгера (например awz.bxorm.App) — файл этого логгера из секции loggers. Последние N строк.',
            'log_set' => 'Включает и настраивает лог Битрикса. type=exception_handling (по умолчанию) — системный лог (секция log в exception_handling .settings.php, по умолчанию класс \Bitrix\Main\Diag\FileExceptionHandlerLog); type=имя PSR-логгера — PSR-лог (создается/обновляется логгер в секции loggers, class \Bitrix\Main\Diag\FileLogger). params — доп. параметры системного лога (JSON или массив): class_name (произвольный класс обработчика, без проверки существования) — в log/class_name; ключи settings (log_size, log_error, log_file_size, date_format, trace_format, format, file) — в log/settings; любые другие ключи — на верхний уровень exception_handling. Каталог файла выбирается автоматически: константа AWZ_BXORM_LOG_PATH, затем существующий /local/logs/, затем /local/php_interface/, иначе /bitrix/php_interface/. В file также можно указать полный путь (например /var/www/site/bitrix/php_interface/exceptions.log) — тогда он используется как есть и записывается в settings.file полностью. Отключение — инструмент log_off.',
            'log_off' => 'Полностью отключает системное логирование и debug. type=exception_handling (по умолчанию) — в .settings.php ставится exception_handling.debug=false и удаляется секция log; type=имя PSR-логгера — логгер удаляется из секции loggers.',
            'log_clear' => 'Усекает (ротирует) лог-файл: содержимое обнуляется, настройки логирования сохраняются и продолжают работать. Файл берется из настроек (type=exception_handling — секция log .settings.php; type=имя PSR-логгера — файл логгера) или задается явно параметром file (те же ограничения, что у file_get).',
            'file_get' => 'Возвращает содержимое файла (путь абсолютный или от корня сайта) — целиком или выбранные строки. Используй, чтобы прочитать файл, на который указала ошибка в логе.',
            'iblocks' => 'Возвращает список инфоблоков сайта (ID, название, код, тип, активность, количество элементов). Используй для выбора IBLOCK_ID перед работой с инфоблоками. Параметры: name — подстрока поиска по названию, type — фильтр по типу инфоблока (IBLOCK_TYPE_ID), active_only — Y (по умолчанию, только активные) / N.',
            'sections' => 'Возвращает список активных разделов инфоблока (ID, NAME, IBLOCK_SECTION_ID, CODE, SECTION_PAGE_URL, DEPTH_LEVEL) в порядке дерева. Используй после iblocks для выбора SECTION_ID. Параметр iblock_id обязателен.',
            'loadphoto' => 'Сохраняет постоянное изображение из base64 в /upload/awz.load.ai/ и возвращает публичный URL. Используй для картинок, вставляемых в HTML внутри материалов. Поддерживаемые форматы: png, jpg, jpeg, webp, svg, gif. Параметр photo — base64-строка (допустим префикс data:image/...;base64,).',
            'tmpphoto' => 'Сохраняет временное изображение из base64 в /upload/awz.gen.tmp/ и возвращает публичный URL. Используй для загрузки в фото превью/детальное (файлы старше 1 часа удаляются автоматически). Поддерживаемые форматы: png, jpg, jpeg, webp, svg, gif. Параметр photo — base64-строка (допустим префикс data:image/...;base64,).',
            'reindexitem' => 'Обновляет поисковый индекс и фасетный индекс элемента инфоблока и пересчитывает наследуемые мета-значения. Вызывай после изменения элемента/свойств, чтобы изменения появились в поиске и умном фильтре. В параметрах только id элемента — ID инфоблока определяется автоматически.',
            'updateitemprop' => 'Обновляет значение свойства элемента инфоблока по коду свойства. Параметры: id элемента, code — код свойства, value — новое значение. После изменения вызывай reindexitem для обновления индексов.',
            'getitem' => 'Возвращает данные элемента инфоблока: все поля + непустые свойства (CODE, NAME, TYPE — тип свойства, VALUE). Поиск по id элемента или по фильтру (JSON-строка, например {"CODE":"my-code"}). limit — количество элементов в ответе (по умолчанию 1, максимум 100; >1 — ответ массив).',
            'updateseoitem' => 'Устанавливает мета-теги элемента инфоблока: page_title (H1 страницы), seo_title (title), seo_descr (description). Пустые параметры не перезаписывают текущие значения.',
            'deletemetaitem' => 'Очищает мета-теги элемента инфоблока (H1, title, description) — сбрасывает индивидуальные шаблоны и кеш наследуемых значений.',
            'additem' => 'Добавляет элемент инфоблока. Параметры: iblock_id (обязателен), name (название, обязателен), description (детальное описание, html определяется автоматически), section_id (ID раздела), preview_picture / detail_picture (путь или URL картинки), active (Y по умолчанию / N), fields — доп. поля JSON-строкой (в т.ч. PROPERTY_<КОД> для свойств, PREVIEW_TEXT, CODE и др.). Символьный код (CODE) если не передан — генерируется автоматически, уникальный в пределах инфоблока. После изменения вызывай reindexitem.',
            'updateitemfields' => 'Изменяет элемент инфоблока. Параметры: id (обязателен), name, description, section_id, preview_picture / detail_picture (путь или URL; "false"/"del" — удалить файл), active (меняется только при явной передаче Y/N), fields — доп. поля JSON-строкой (в т.ч. PROPERTY_<КОД>). Пустые параметры не перезаписывают текущие значения. После изменения вызывай reindexitem.',
            'updateseosect' => 'Устанавливает мета-теги раздела инфоблока: page_title (H1 страницы), seo_title (title), seo_descr (description). Пустые параметры не перезаписывают текущие значения.',
            'addsection' => 'Добавляет раздел инфоблока. Параметры: iblock_id (обязателен), name (название, обязателен), description (описание, html определяется автоматически), parent_id (ID родительского раздела, 0 — корень), code (символьный код; если не передан — генерируется автоматически, уникальный в пределах инфоблока), active (Y по умолчанию / N), picture / detail_picture (путь или URL картинок), fields — доп. поля JSON-строкой, в т.ч. UF_<КОД> (пользовательские свойства раздела) и SORT.',
            'updatesection' => 'Изменяет раздел инфоблока. Параметры: id (обязателен), name, description, parent_id (ID родительского раздела; пусто — не менять, "0" — перенести в корень), code, active (меняется только при явной передаче Y/N), picture / detail_picture (путь или URL; "false"/"del" — удалить файл), fields — доп. поля JSON-строкой, в т.ч. UF_<КОД> (пользовательские свойства раздела). Пустые параметры не перезаписывают текущие значения.',
            'getsection' => 'Возвращает данные раздела инфоблока: все поля + значения UF_* (пользовательские свойства раздела). Поиск по id раздела или по фильтру (JSON-строка, например {"CODE":"my-code"} или {"IBLOCK_ID":5,"=NAME":"Каталог"}). limit — количество разделов в ответе (по умолчанию 1, максимум 100; >1 — ответ массив).',
            'deletemetasection' => 'Очищает мета-теги раздела инфоблока (H1, title, description) — сбрасывает индивидуальные шаблоны и кеш наследуемых значений.',
            'orders' => 'Ищет заказы (модуль sale) по фильтру, максимум 50 заказов за вызов, сортировка ID DESC. filter — JSON-объект с полями заказа и операторами: {"STATUS_ID":"N"}, {"PAYED":"Y"}, {"USER_ID":5}, {"<ID":1000}, {">ID":0}, {"ACCOUNT_NUMBER":"..."} и т.д. Параметр fields — JSON-массив кодов полей (по умолчанию ID, ACCOUNT_NUMBER, DATE_INSERT, STATUS_ID, PAYED, CANCELED, PRICE, CURRENCY, USER_ID и др.). Пагинация: для следующей страницы вызывай снова с filter={"<ID": <минимальный ID из текущей выдачи>}.',
            'orderget' => 'Возвращает детальную информацию о заказе (модуль sale) в JSON: поля заказа (статус, оплата, цены, пользователь, комментарии), список оплат (PAID, платежная система, чек), отгрузки (служба доставки, статус, трек-номер) и состав корзины (товары, количество, цены). Поиск по order_id или account_number.',
            'orderstatuses' => 'Возвращает список статусов заказа (модуль sale) с кодами и названиями. Используй перед orderstatus, чтобы узнать допустимые коды статусов. Параметр color=Y — только статусы с цветом (доступные для ручной смены в админке).',
            'orderstatus' => 'Меняет статус заказа (модуль sale). Параметры: order_id — ID заказа (обязателен), status — код статуса (список — инструмент orderstatuses). Запускает события смены статуса (письма, интеграции).',
            'orderpay' => 'Устанавливает оплату заказа (модуль sale). Параметры: order_id — ID заказа (обязателен), paid — Y (оплачен, по умолчанию) / N (не оплачен), payment_id — ID конкретной оплаты (опционально; без него меняются все оплаты заказа). Запускает события оплаты.'
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
            ],
            'iblocks' => [
                'properties' => [
                    'name' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Подстрока для поиска по названию инфоблока'
                    ),
                    'type' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Фильтр по типу инфоблока (IBLOCK_TYPE_ID)'
                    ),
                    'active_only' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Y (по умолчанию) — только активные инфоблоки; N — все'
                    ),
                ],
                'required' => []
            ],
            'sections' => [
                'properties' => [
                    'iblock_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID инфоблока (получить через инструмент iblocks). Обязательно.'
                    ),
                ],
                'required' => ['iblock_id']
            ],
            'loadphoto' => [
                'properties' => [
                    'photo' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Изображение в base64 (допустим префикс data:image/...;base64,). Форматы: png, jpg, jpeg, webp, svg, gif. Обязательно.'
                    ),
                ],
                'required' => ['photo']
            ],
            'tmpphoto' => [
                'properties' => [
                    'photo' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Изображение в base64 (допустим префикс data:image/...;base64,). Форматы: png, jpg, jpeg, webp, svg, gif. Обязательно.'
                    ),
                ],
                'required' => ['photo']
            ],
            'reindexitem' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока. Обязательно.'
                    ),
                ],
                'required' => ['id']
            ],
            'updateitemprop' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока. Обязательно.'
                    ),
                    'code' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Код свойства (CODE). Обязательно.'
                    ),
                    'value' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Новое значение свойства (для множественного — массив в JSON).'
                    ),
                ],
                'required' => ['id', 'code', 'value']
            ],
            'getitem' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока (приоритетнее filter).'
                    ),
                    'filter' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Фильтр поиска элемента — JSON-строка, например {"CODE":"my-code"} или {"IBLOCK_ID":5,"NAME":"Товар"}. Используется, если id не задан.'
                    ),
                    'limit' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Количество элементов в ответе (по умолчанию 1, максимум 100). При limit>1 ответ — массив элементов.'
                    ),
                ],
                'required' => []
            ],
            'updateseoitem' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока. Обязательно.'
                    ),
                    'page_title' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'H1 страницы элемента (ELEMENT_PAGE_TITLE). Пусто — не менять.'
                    ),
                    'seo_title' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Title страницы (ELEMENT_META_TITLE). Пусто — не менять.'
                    ),
                    'seo_descr' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Description страницы (ELEMENT_META_DESCRIPTION). Пусто — не менять.'
                    ),
                ],
                'required' => ['id']
            ],
            'deletemetaitem' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока. Обязательно.'
                    ),
                ],
                'required' => ['id']
            ],
            'updateseosect' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока. Обязательно.'
                    ),
                    'page_title' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'H1 страницы раздела (SECTION_PAGE_TITLE). Пусто — не менять.'
                    ),
                    'seo_title' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Title страницы (SECTION_META_TITLE). Пусто — не менять.'
                    ),
                    'seo_descr' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Description страницы (SECTION_META_DESCRIPTION). Пусто — не менять.'
                    ),
                ],
                'required' => ['id']
            ],
            'deletemetasection' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока. Обязательно.'
                    ),
                ],
                'required' => ['id']
            ],
            'addsection' => [
                'properties' => [
                    'iblock_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID инфоблока (получить через инструмент iblocks). Обязательно.'
                    ),
                    'name' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Название раздела. Обязательно.'
                    ),
                    'description' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Описание раздела (DESCRIPTION). Тип html/text определяется автоматически.'
                    ),
                    'parent_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID родительского раздела (IBLOCK_SECTION_ID). 0 или пусто — раздел в корне.'
                    ),
                    'code' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Символьный код (CODE). Если не передан — генерируется автоматически из названия, уникальный в пределах инфоблока.'
                    ),
                    'active' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Активность раздела: Y (по умолчанию) / N.'
                    ),
                    'picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL картинки анонса раздела (PICTURE).'
                    ),
                    'detail_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL детальной картинки (DETAIL_PICTURE).'
                    ),
                    'fields' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Доп. поля — JSON-строка, например {"UF_DESCRIPTION":"Текст","SORT":100}. UF_<КОД> — значение пользовательского свойства раздела.'
                    ),
                ],
                'required' => ['iblock_id', 'name']
            ],
            'updatesection' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока. Обязательно.'
                    ),
                    'name' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Название раздела. Пусто — не менять.'
                    ),
                    'description' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Описание раздела (DESCRIPTION). Пусто — не менять.'
                    ),
                    'parent_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID родительского раздела (IBLOCK_SECTION_ID). Пусто — не менять; "0" — перенести в корень.'
                    ),
                    'code' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Символьный код (CODE). Пусто — не менять.'
                    ),
                    'active' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Активность раздела: Y / N. Меняется только при явной передаче.'
                    ),
                    'picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL картинки анонса раздела (PICTURE); "false"/"del" — удалить файл. Пусто — не менять.'
                    ),
                    'detail_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL детальной картинки (DETAIL_PICTURE); "false"/"del" — удалить файл. Пусто — не менять.'
                    ),
                    'fields' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Доп. поля — JSON-строка, например {"UF_DESCRIPTION":"Текст","SORT":100}. UF_<КОД> — значение пользовательского свойства раздела.'
                    ),
                ],
                'required' => ['id']
            ],
            'getsection' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока (приоритетнее filter).'
                    ),
                    'filter' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Фильтр поиска раздела — JSON-строка, например {"CODE":"my-code"} или {"IBLOCK_ID":5,"=NAME":"Каталог"}. Используется, если id не задан.'
                    ),
                    'limit' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Количество разделов в ответе (по умолчанию 1, максимум 100). При limit>1 ответ — массив разделов.'
                    ),
                ],
                'required' => []
            ],
            'additem' => [
                'properties' => [
                    'iblock_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID инфоблока (получить через инструмент iblocks). Обязательно.'
                    ),
                    'name' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Название элемента. Обязательно.'
                    ),
                    'description' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Детальное описание (DETAIL_TEXT). Тип html/text определяется автоматически.'
                    ),
                    'section_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока (IBLOCK_SECTION).'
                    ),
                    'preview_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL картинки анонса (PREVIEW_PICTURE).'
                    ),
                    'detail_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL детальной картинки (DETAIL_PICTURE).'
                    ),
                    'active' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Активность элемента: Y (по умолчанию) / N.'
                    ),
                    'fields' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Доп. поля — JSON-строка, например {"CODE":"my-code","PROPERTY_MATERIAL":"Сталь","PREVIEW_TEXT":"Анонс"}. PROPERTY_<КОД> — значение свойства.'
                    ),
                ],
                'required' => ['iblock_id', 'name']
            ],
            'updateitemfields' => [
                'properties' => [
                    'id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID элемента инфоблока. Обязательно.'
                    ),
                    'name' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Название элемента. Пусто — не менять.'
                    ),
                    'description' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Детальное описание (DETAIL_TEXT). Пусто — не менять.'
                    ),
                    'section_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID раздела инфоблока (IBLOCK_SECTION). 0/пусто — не менять.'
                    ),
                    'preview_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL картинки анонса; "false"/"del" — удалить файл. Пусто — не менять.'
                    ),
                    'detail_picture' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Путь или URL детальной картинки; "false"/"del" — удалить файл. Пусто — не менять.'
                    ),
                    'active' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Активность элемента: Y / N. Меняется только при явной передаче.'
                    ),
                    'fields' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Доп. поля — JSON-строка, например {"CODE":"my-code","PROPERTY_MATERIAL":"Сталь"}. PROPERTY_<КОД> — значение свойства.'
                    ),
                ],
                'required' => ['id']
            ],
            'orders' => [
                'properties' => [
                    'filter' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Фильтр — JSON-объект: {"STATUS_ID":"N"}, {"PAYED":"Y"}, {"USER_ID":5}, {"<ID":1000}, {">ID":0}. Для следующей страницы: {"<ID": <минимальный ID предыдущей выдачи>}. Пусто — все заказы (первые 50).'
                    ),
                    'fields' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'JSON-массив кодов полей заказа, например ["ID","ACCOUNT_NUMBER","STATUS_ID","PRICE"]. Пусто — стандартный набор полей.'
                    ),
                ],
                'required' => []
            ],
            'orderget' => [
                'properties' => [
                    'order_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID заказа (приоритетнее account_number).'
                    ),
                    'account_number' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Номер заказа (ACCOUNT_NUMBER); используется, если order_id не задан.'
                    ),
                ],
                'required' => []
            ],
            'orderstatuses' => [
                'properties' => [
                    'color' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Y — только статусы с цветом (доступные для ручной смены в админке); пусто — все'
                    ),
                ],
                'required' => []
            ],
            'orderstatus' => [
                'properties' => [
                    'order_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID заказа. Обязательно.'
                    ),
                    'status' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Код статуса (ID из инструмента orderstatuses). Обязательно.'
                    ),
                ],
                'required' => ['order_id', 'status']
            ],
            'orderpay' => [
                'properties' => [
                    'order_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID заказа. Обязательно.'
                    ),
                    'paid' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'Y — оплатить (по умолчанию), N — снять оплату'
                    ),
                    'payment_id' => new DTO\Tool\PropertySchema(
                        type: 'string',
                        description: 'ID конкретной оплаты заказа; пусто — все оплаты заказа'
                    ),
                ],
                'required' => ['order_id']
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

    /**
     * Поиск заказов (sale) по фильтру, максимум 50 заказов, сортировка ID DESC.
     * filter — JSON-объект с полями заказа, в т.ч. с операторами: {"<ID": 1000} — следующая страница,
     * {">ID": 0}, {"STATUS_ID":"N"}, {"PAYED":"Y"}, {"USER_ID":5}, {"ACCOUNT_NUMBER":"..."} и т.д.
     * Для следующей страницы используй {"<ID": <минимальный ID из выдачи>}.
     */
    public function ordersAction(string $filter = '', string $fields = ''): array
    {
        try{
            if(!Loader::includeModule('sale')){
                return ['content'=>[['type'=>'text','text'=>'Модуль sale не установлен']], 'isError'=>true];
            }
            $limit = 50;
            $arFilter = [];
            $filter = trim($filter);
            if($filter !== ''){
                $decoded = json_decode($filter, true);
                if(!is_array($decoded)){
                    return ['content'=>[['type'=>'text','text'=>'некорректный JSON в параметре filter (ожидается объект вида {"STATUS_ID":"N"})']], 'isError'=>true];
                }
                foreach($decoded as $key=>$val){
                    $key = trim((string)$key);
                    if($key === '') continue;
                    if(preg_match('/^(>=|<=|<>|>|<|=|!|~|%)?[\w.]+$/i', $key)){
                        $arFilter[$key] = $val;
                    }
                }
            }

            $select = ['ID','ACCOUNT_NUMBER','DATE_INSERT','DATE_UPDATE','STATUS_ID','PAYED','CANCELED',
                'ALLOW_DELIVERY','DEDUCTED','PRICE','SUM_PAID','CURRENCY','USER_ID','RESPONSIBLE_ID','PAY_SYSTEM_ID',
                'DELIVERY_ID','COMMENTS'];
            $fields = trim($fields);
            if($fields !== ''){
                $decoded = json_decode($fields, true);
                if(is_array($decoded)){
                    $custom = array_values(array_filter(array_map('strval', $decoded), function($v){ return $v !== ''; }));
                    if(!empty($custom)){
                        $select = array_values(array_unique(array_merge(['ID'], $custom)));
                    }
                }
            }

            $rows = [];
            try{
                $res = \Bitrix\Sale\Internals\OrderTable::getList([
                    'select' => $select,
                    'filter' => $arFilter,
                    'order' => ['ID' => 'DESC'],
                    'limit' => $limit,
                ]);
                $rows = $res->fetchAll();
            }catch(\Throwable $e){
                return ['content'=>[['type'=>'text','text'=>'Ошибка запроса заказов: '.$e->getMessage()]], 'isError'=>true];
            }

            if(empty($rows)){
                return ['content'=>[['type'=>'text','text'=>'Заказы не найдены (filter='.$filter.')']]];
            }

            foreach($rows as &$row){
                if(isset($row['DATE_INSERT']) && $row['DATE_INSERT'] instanceof \Bitrix\Main\Type\DateTime){
                    $row['DATE_INSERT'] = $row['DATE_INSERT']->toString();
                }
                if(isset($row['DATE_UPDATE']) && $row['DATE_UPDATE'] instanceof \Bitrix\Main\Type\DateTime){
                    $row['DATE_UPDATE'] = $row['DATE_UPDATE']->toString();
                }
            }
            unset($row);

            $hasMore = count($rows) >= $limit;
            $text = json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $out = 'Найдено заказов: '.count($rows).' (макс. '.$limit.', сортировка ID DESC)';
            if($hasMore){
                $out .= "\nВозможно, есть еще заказы — для следующей страницы вызови снова с filter={\"<ID\":".$rows[count($rows)-1]['ID']."}";
            }
            $out .= "\n".$text;

            return ['content'=>[['type'=>'text','text'=>$out]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool orders error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Детальная информация о заказе (sale): поля заказа, оплаты, отгрузки, корзина.
     * order_id — ID заказа (или ACCOUNT_NUMBER в параметре account_number).
     */
    public function ordergetAction(string $order_id = '', string $account_number = ''): array
    {
        try{
            if(!Loader::includeModule('sale')){
                return ['content'=>[['type'=>'text','text'=>'Модуль sale не установлен']], 'isError'=>true];
            }
            $orderId = (int)$order_id;
            $accountNumber = trim($account_number);
            if(!$orderId && $accountNumber === ''){
                return ['content'=>[['type'=>'text','text'=>'укажите order_id или account_number']], 'isError'=>true];
            }
            if(!$orderId && $accountNumber !== ''){
                $row = \Bitrix\Sale\Internals\OrderTable::getRow([
                    'select' => ['ID'],
                    'filter' => ['=ACCOUNT_NUMBER' => $accountNumber],
                ]);
                if(!$row){
                    return ['content'=>[['type'=>'text','text'=>'Заказ не найден (ACCOUNT_NUMBER='.$accountNumber.')']], 'isError'=>true];
                }
                $orderId = (int)$row['ID'];
            }

            $order = \Bitrix\Sale\Order::load($orderId);
            if(!$order){
                return ['content'=>[['type'=>'text','text'=>'Заказ не найден: '.$orderId]], 'isError'=>true];
            }

            $fields = $order->getFields()->getValues();
            $result = [
                'ID' => (int)$fields['ID'],
                'ACCOUNT_NUMBER' => (string)$fields['ACCOUNT_NUMBER'],
                'DATE_INSERT' => (isset($fields['DATE_INSERT']) && $fields['DATE_INSERT'] instanceof \Bitrix\Main\Type\DateTime)
                    ? $fields['DATE_INSERT']->toString() : (string)($fields['DATE_INSERT'] ?? ''),
                'DATE_UPDATE' => (isset($fields['DATE_UPDATE']) && $fields['DATE_UPDATE'] instanceof \Bitrix\Main\Type\DateTime)
                    ? $fields['DATE_UPDATE']->toString() : (string)($fields['DATE_UPDATE'] ?? ''),
                'STATUS_ID' => (string)$fields['STATUS_ID'],
                'CANCELED' => (string)$fields['CANCELED'],
                'PAYED' => (string)$fields['PAYED'],
                'ALLOW_DELIVERY' => (string)$fields['ALLOW_DELIVERY'],
                'PRICE' => (float)$fields['PRICE'],
                'SUM_PAID' => (float)$fields['SUM_PAID'],
                'CURRENCY' => (string)$fields['CURRENCY'],
                'USER_ID' => (int)$fields['USER_ID'],
                'USER_DESCRIPTION' => (string)($fields['USER_DESCRIPTION'] ?? ''),
                'COMMENTS' => (string)($fields['COMMENTS'] ?? ''),
                'REASON_CANCELED' => (string)($fields['REASON_CANCELED'] ?? ''),
                'LOCK_STATUS' => (string)($fields['LOCK_STATUS'] ?? ''),
            ];

            $payments = [];
            foreach($order->getPayment() as $payment){
                $payments[] = [
                    'ID' => (int)$payment->getId(),
                    'PAY_SYSTEM_ID' => (int)$payment->getPaymentSystemId(),
                    'PAID' => (string)$payment->getField('PAID'),
                    'SUM' => (float)$payment->getSum(),
                    'PAY_VOUCHER_NUM' => (string)($payment->getField('PAY_VOUCHER_NUM') ?? ''),
                    'DATE_PAID' => ($payment->getField('DATE_PAID') instanceof \Bitrix\Main\Type\DateTime)
                        ? $payment->getField('DATE_PAID')->toString() : '',
                    'PS_STATUS' => (string)($payment->getField('PS_STATUS') ?? ''),
                ];
            }
            $result['PAYMENTS'] = $payments;

            $shipments = [];
            foreach($order->getShipmentCollection() as $shipment){
                if((int)$shipment->getSystemShipmentId() > 0 && $shipment->isSystem()) continue;
                $shipments[] = [
                    'ID' => (int)$shipment->getId(),
                    'DELIVERY_ID' => (int)$shipment->getDeliveryId(),
                    'STATUS_ID' => (string)$shipment->getField('STATUS_ID'),
                    'DEDUCTED' => (string)$shipment->getField('DEDUCTED'),
                    'ALLOW_DELIVERY' => (string)$shipment->getField('ALLOW_DELIVERY'),
                    'PRICE_DELIVERY' => (float)$shipment->getPrice(),
                    'TRACKING_NUMBER' => (string)($shipment->getField('TRACKING_NUMBER') ?? ''),
                ];
            }
            $result['SHIPMENTS'] = $shipments;

            $basket = [];
            foreach($order->getBasket() as $item){
                /** @var \Bitrix\Sale\BasketItem $item */
                if($item->isBundleParent()) continue;
                $basket[] = [
                    'ID' => (int)$item->getId(),
                    'PRODUCT_ID' => (int)$item->getProductId(),
                    'NAME' => (string)$item->getField('NAME'),
                    'SKU' => (string)($item->getField('PRODUCT_XML_ID') ?? ''),
                    'QUANTITY' => (float)$item->getQuantity(),
                    'PRICE' => (float)$item->getPrice(),
                    'DISCOUNT_PRICE' => (float)$item->getDiscountPrice(),
                    'SUM' => (float)($item->getFinalPrice()),
                ];
            }
            $result['BASKET'] = $basket;

            return ['content'=>[['type'=>'text','text'=>json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool orderget error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Возвращает список статусов заказа (sale) — для выбора STATUS_ID.
     * color — Y: только статусы с цветом (видимые в админке для смены вручную).
     */
    public function orderstatusesAction(string $color = ''): array
    {
        try{
            if(!Loader::includeModule('sale')){
                return ['content'=>[['type'=>'text','text'=>'Модуль sale не установлен']], 'isError'=>true];
            }
            $rows = [];
            $res = \CSaleStatus::GetList([], [], false, false, ['ID','NAME','SORT','COLOR']);
            while($row = $res->Fetch()){
                $name = '';
                foreach((array)($row['NAME'] ?? []) as $lid=>$val){ $name = (string)$val; }
                if(is_string($row['NAME'] ?? null) && $row['NAME'] !== '') $name = (string)$row['NAME'];
                $rows[] = [
                    'ID' => (string)$row['ID'],
                    'NAME' => $name,
                    'SORT' => (int)($row['SORT'] ?? 0),
                    'COLOR' => (string)($row['COLOR'] ?? ''),
                ];
            }
            if(trim($color) === 'Y'){
                $rows = array_values(array_filter($rows, function($r){ return $r['COLOR'] !== ''; }));
            }
            if(empty($rows)){
                return ['content'=>[['type'=>'text','text'=>'Статусы не найдены']]];
            }
            $out = ['Статусов: '.count($rows)];
            foreach($rows as $r){
                $out[] = '['.$r['ID'].'] '.$r['NAME'].' | сортировка: '.$r['SORT'];
            }
            return ['content'=>[['type'=>'text','text'=>implode("\n", $out)]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool orderstatuses error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Меняет статус заказа (sale). order_id — ID заказа, status — код статуса (ID из orderstatuses).
     */
    public function orderstatusAction(string $order_id, string $status = ''): array
    {
        try{
            if(!Loader::includeModule('sale')){
                return ['content'=>[['type'=>'text','text'=>'Модуль sale не установлен']], 'isError'=>true];
            }
            $orderId = (int)$order_id;
            if(!$orderId){
                return ['content'=>[['type'=>'text','text'=>'не указан ID заказа (параметр order_id)']], 'isError'=>true];
            }
            $status = trim($status);
            if($status === ''){
                return ['content'=>[['type'=>'text','text'=>'не указан код статуса (параметр status), список — инструмент orderstatuses']], 'isError'=>true];
            }

            $order = \Bitrix\Sale\Order::load($orderId);
            if(!$order){
                return ['content'=>[['type'=>'text','text'=>'Заказ не найден: '.$orderId]], 'isError'=>true];
            }
            $oldStatus = (string)$order->getField('STATUS_ID');
            $r = $order->setField('STATUS_ID', $status);
            if(!$r->isSuccess()){
                return ['content'=>[['type'=>'text','text'=>'Ошибка установки статуса: '.implode('; ', $r->getErrorMessages())]], 'isError'=>true];
            }
            $r = $order->save();
            if(!$r->isSuccess()){
                return ['content'=>[['type'=>'text','text'=>'Ошибка сохранения заказа: '.implode('; ', $r->getErrorMessages())]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Заказ #'.$orderId.': статус '.$oldStatus.' → '.$status]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool orderstatus error: '.$e->getMessage()]], 'isError'=>true];
        }
    }

    /**
     * Устанавливает оплату заказа (sale). order_id — ID заказа,
     * paid — Y (оплачен) / N (не оплачен); payment_id — ID конкретной оплаты (опционально, иначе все).
     */
    public function orderpayAction(string $order_id, string $paid = 'Y', string $payment_id = ''): array
    {
        try{
            if(!Loader::includeModule('sale')){
                return ['content'=>[['type'=>'text','text'=>'Модуль sale не установлен']], 'isError'=>true];
            }
            $orderId = (int)$order_id;
            if(!$orderId){
                return ['content'=>[['type'=>'text','text'=>'не указан ID заказа (параметр order_id)']], 'isError'=>true];
            }
            $paid = strtoupper(trim($paid));
            if($paid !== 'Y' && $paid !== 'N'){
                return ['content'=>[['type'=>'text','text'=>'параметр paid должен быть Y или N']], 'isError'=>true];
            }
            $paymentId = (int)$payment_id;

            $order = \Bitrix\Sale\Order::load($orderId);
            if(!$order){
                return ['content'=>[['type'=>'text','text'=>'Заказ не найден: '.$orderId]], 'isError'=>true];
            }
            $paymentCollection = $order->getPayment();
            if(!$paymentCollection || !count($paymentCollection)){
                return ['content'=>[['type'=>'text','text'=>'У заказа нет оплат']], 'isError'=>true];
            }

            $changed = [];
            foreach($paymentCollection as $payment){
                if($paymentId > 0 && (int)$payment->getId() !== $paymentId) continue;
                $r = $payment->setPaid($paid);
                if(!$r->isSuccess()){
                    return ['content'=>[['type'=>'text','text'=>'Ошибка установки оплаты #'.$payment->getId().': '.implode('; ', $r->getErrorMessages())]], 'isError'=>true];
                }
                $changed[] = $payment->getId();
            }
            if(empty($changed)){
                return ['content'=>[['type'=>'text','text'=>'Оплата не найдена: #'.$paymentId]], 'isError'=>true];
            }
            $r = $order->save();
            if(!$r->isSuccess()){
                return ['content'=>[['type'=>'text','text'=>'Ошибка сохранения заказа: '.implode('; ', $r->getErrorMessages())]], 'isError'=>true];
            }

            return ['content'=>[['type'=>'text','text'=>'Заказ #'.$orderId.': paid='.$paid.', оплаты: '.implode(', ', $changed)]]];
        }catch(\Throwable $e){
            return ['content'=>[['type'=>'text','text'=>'tool orderpay error: '.$e->getMessage()]], 'isError'=>true];
        }
    }
}