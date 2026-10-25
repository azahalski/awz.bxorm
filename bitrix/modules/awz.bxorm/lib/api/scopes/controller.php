<?php
namespace Awz\BxOrm\Api\Scopes;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\Controller as BxController;
use Bitrix\Main\Error;
use Bitrix\Main\Request;

use Bitrix\Main\Response;
use Psr\Log;
use Bitrix\Main\Diag;
use Bitrix\Main\Web\Json;

class Controller extends BxController implements Log\LoggerAwareInterface
{
    use Log\LoggerAwareTrait;

    public bool $processingError = false;
    public ScopeCollection $scopesCollection;

    protected ?string $requestId = null;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);

        $this->scopesCollection = new ScopeCollection();
    }

    /**
     * Идентификатор запроса — сквозной по всем записям лога одного вызова
     */
    public function getRequestId(): string
    {
        if($this->requestId === null){
            try{
                $this->requestId = \Bitrix\Main\Security\Random::getString(12, true);
            }catch(\Throwable $e){
                $this->requestId = uniqid();
            }
        }
        return $this->requestId;
    }

    /**
     * Значение для лог-контекста: массивы/объекты — json в одну строку,
     * переносы строк в скалярах заменяются (лог должен быть однострочным)
     */
    protected function logValue($value): string
    {
        if($value instanceof \Throwable){
            $value = [
                'error' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile().':'.$value->getLine()
            ];
        }
        if(is_array($value) || is_object($value)){
            try{
                return Json::encode($value, JSON_UNESCAPED_UNICODE);
            }catch(\Throwable $e){
                $json = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
                return $json !== false ? $json : '';
            }
        }
        return str_replace(["\r\n", "\n", "\r"], ' \\n ', (string)$value);
    }

    /**
     * Компактная запись лога в одну строку: [tag] {requestId} | {date} | key: value | ...
     */
    protected function logLine(string $level, string $tag, array $data = []): void
    {
        if($logger = $this->getLogger()){
            $parts = [];
            foreach($data as $key=>$value){
                $parts[] = $key.': '.$this->logValue($value);
            }
            $logger->log(
                $level,
                "{requestId} [{$tag}] | {date} | ".implode(' | ', $parts)."\n",
                ['requestId' => $this->getRequestId()]
            );
        }
    }

    /**
     * Трассировка исключения в компактный список строк file:line
     */
    protected function compactTrace(\Throwable $throwable): array
    {
        $trace = [];
        foreach($throwable->getTrace() as $i=>$item){
            if($i >= 15) break;
            $trace[] = '#'.$i.' '.($item['class'] ?? '').($item['type'] ?? '').($item['function'] ?? '')
                .' '.($item['file'] ?? '').':'.($item['line'] ?? '');
        }
        return $trace;
    }

    protected function runProcessingThrowable(\Throwable $throwable)
    {
        $this->processingError = true;
        if ($throwable instanceof BinderArgumentException)
        {
            $currentControllerErrors = $this->getErrors();
            $errors = $throwable->getErrors();
            if ($errors)
            {
                foreach ($errors as $error)
                {
                    if (in_array($error, $currentControllerErrors, true)) continue;
                    $this->errorCollection[] = $error;
                }
            }elseif ($throwable instanceof ArgumentNullException){
                $this->errorCollection[] = new Error(
                    $throwable->getMessage(), self::ERROR_REQUIRED_PARAMETER
                );
            }else{
                $this->errorCollection[] = new Error($throwable->getMessage(), $throwable->getCode());
            }
            $this->logLine('error', 'args error', [
                'code' => $throwable->getCode(),
                'mess' => $throwable->getMessage(),
                'file' => $throwable->getFile().':'.$throwable->getLine(),
                'trace' => $this->compactTrace($throwable)
            ]);
        }
        elseif ($throwable instanceof \Exception)
        {
            if ($throwable instanceof ArgumentNullException)
            {
                $this->errorCollection[] = new Error(
                    $throwable->getMessage(), self::ERROR_REQUIRED_PARAMETER
                );
            }else{
                $this->errorCollection[] = new Error($throwable->getMessage(), $throwable->getCode());
            }
            $this->logLine('error', 'php error', [
                'code' => $throwable->getCode(),
                'mess' => $throwable->getMessage(),
                'file' => $throwable->getFile().':'.$throwable->getLine(),
                'trace' => $this->compactTrace($throwable)
            ]);
        }
        elseif ($throwable instanceof \Error)
        {
            $this->logLine('critical', 'php error', [
                'code' => $throwable->getCode(),
                'mess' => $throwable->getMessage(),
                'file' => $throwable->getFile().':'.$throwable->getLine(),
                'trace' => $this->compactTrace($throwable)
            ]);
            $this->errorCollection[] = new Error('php error', $throwable->getCode());
        }
    }

    protected function addError(Error $error): Controller
    {
        if($this->processingError) {
            $this->processingError = false;
            return $this;
        }

        $this->errorCollection[] = $error;

        return $this;
    }

    public function getScopeCollection(): ScopeCollection
    {
        return $this->scopesCollection;
    }

    public function addScopeApi(Scope $scope){
        $scopeCurrent = $this->getScopeCollection()->getByCode($scope->getCode());
        if($scopeCurrent){
            $scopeCurrent->setStatus($scope->getStatus());
        }else{
            $this->getScopeCollection()->add([$scope]);
        }
    }

    /**
     * @param string[] $scopes
     * @return bool
     */
    final public function checkRequire(array $scopes = array()): bool
    {
        if(empty($scopes)) return true;
        $collection = $this->getScopeCollection();
        /* @var ScopeCollection $collection */
        foreach($scopes as $scopeCode){
            $scope = $collection->getByCode($scopeCode);
            if(!$scope) return false;
            if(!$scope->isEnabled()) return false;
        }
        return true;
    }

    public function finalizeResponse(Response $response)
    {
        if(!empty($this->getErrors())){
            foreach($this->getErrors() as $error){
                $this->logLine('error', 'error', [
                    'code' => $error->getCode(),
                    'mess' => $error->getMessage()
                ]);
            }
        }else{
            $this->logLine('debug', 'response', [
                'resp' => $response->getContent()
            ]);
        }
    }

    public function getLogger()
    {
        if ($this->logger === null)
        {
            $names = explode("\\", get_class($this));
            $logger = Diag\Logger::create('awz.bxorm.'.end($names), [$this]);

            if ($logger !== null)
            {
                $this->setLogger($logger);
            }
        }

        return $this->logger;
    }
}