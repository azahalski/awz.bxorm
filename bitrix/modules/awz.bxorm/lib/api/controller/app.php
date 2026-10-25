<?php
namespace Awz\BxOrm\Api\Controller;

use Awz\BxOrm\Api\BinderResult;
use Awz\BxOrm\Api\Scopes\Parameters;
use Awz\BxOrm\Api\Scopes\Scope;
use Awz\BxOrm\Api\Scopes\Controller;
use Awz\BxOrm\Api\Filters\AppAuth;
use Awz\BxOrm\Api\Filters\CheckMethod;
use Awz\BxOrm\Api\Filters\NoCors;
use Awz\BxOrm\HooksTable;
use Awz\BxOrm\MethodsTable;
use Awz\BxOrm\Helper;
use Bitrix\Main\Event;
use Bitrix\Main\Request;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Response;
use Bitrix\Main\Web\Json;
use Awz\BxOrm\Api\Filters\Request\ReplaceFilter;
use Bitrix\Main\Type\ParameterDictionary;

Loc::loadMessages(__FILE__);

/**
 * Универсальный API-контроллер: call/batch/methods для своих контроллеров и ORM-сущностей.
 * Тип метода определяет запись MethodsTable (ENTITY) — транспорт обрабатывает оба варианта.
 * Отличие от Hook: в call можно передавать любые параметры (id, filter и т.д. — без
 * запрещенных ключей). Реализация ORM-методов (fields/add/list/get/update/delete) общая.
 * Транспорт-родственники: Hook (стандартные ограничения параметров), BxMcp (MCP JSON-RPC).
 */
class App extends Controller
{
    protected BinderResult $binderResult;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
        $this->binderResult = new BinderResult();
    }

    public function configureActions()
    {
        $config = [
            'methods' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ]
            ],
            'batch' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ]
            ],
            'call' => [
                'prefilters' => [
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

        return $config;
    }

    public function getBinderResult(): BinderResult
    {
        return $this->binderResult;
    }

    /**
     * replaceMethod + заполнение binder'а параметрами вызова + событие onBeforeHookCallAction.
     * После вызова проверить isSuccess()/getData() и читать параметры через getMethod()/getId()/...
     */
    protected function bindCall(
        int $app, string $method,
        array $order = [], array $select = [], array $filter = [],
        int $start = 0, string $id = "", array $fields = [], array $params = []
    ): BinderResult
    {
        $method = Helper::replaceMethod($method, $app);

        $this->getBinderResult()
            ->setApp($app)->setMethod($method)
            ->setOrder($order)->setSelect($select)
            ->setFilter($filter)->setStart($start)
            ->setId($id)->setFields($fields)
            ->setParams($params);

        $event = new Event(
            "awz.bxorm",
            "onBeforeHookCallAction",
            ['controller'=>$this]
        );
        $event->send();

        return $this->getBinderResult();
    }

    /**
     * Дополнительные проверки параметров вызова. true — проверки пройдены.
     * В App любые параметры разрешены; Hook переопределяет и запрещает зарезервированные ключи.
     */
    protected function checkCallParams(string $method, array $methodsParams, array $params): bool
    {
        return true;
    }

    /**
     * Метод текущего вызова: METHOD_ID из scope CheckMethod → запись MethodsTable.
     * Подключает модули из METHOD.MODULES. null — ошибки добавлены в контроллер.
     */
    protected function resolveMethodParams(): ?array
    {
        $METHOD_ID = $this->getScopeCollection()->getByCode('method')->getCustomData()->get('METHOD_ID');

        if(!$METHOD_ID){
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_PARAM_M_ERR')));
            return null;
        }

        $methodsParams = MethodsTable::getRowById($METHOD_ID);
        if(!$methodsParams){
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_PARAM_M_ERR')));
            return null;
        }

        //подключение модулей
        if(is_array($methodsParams['MODULES'])){
            foreach($methodsParams['MODULES'] as $module){
                if(!Loader::includeModule($module)){
                    $this->addError(new Error($module.' - '.Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_NOT_MODULE')));
                    return null;
                }
            }
        }

        return $methodsParams;
    }

    /**
     * Является ли ENTITY контроллером (а не ORM-сущностью)
     */
    protected function isControllerEntity($cls): bool
    {
        return $cls && is_string($cls) && class_exists($cls) && method_exists($cls, 'listNameActions');
    }

    /**
     * Проброс scopes (method и др.) в sub-контроллер — иначе он не знает METHOD_ID текущего вызова.
     * Только для наследников Scopes\Controller — свои контроллеры сайтов могут наследовать
     * обычный Bitrix\Main\Engine\Controller.
     */
    protected function propagateScopes(Controller $controller): void
    {
        foreach($this->getScopeCollection() as $scope){
            $controller->addScopeApi($scope);
        }
    }

    /**
     * Вызов экшена sub-контроллера: подкласс из второго сегмента метода (tools/call → Mcp\Tools),
     * проброс scopes, запуск на Bitrix Engine\Controller.
     */
    protected function dispatchToController(string $method, array $methodsParams, array $allParams)
    {
        $cls = $methodsParams['ENTITY'];

        $methodAr = explode('.',$method);
        if(isset($methodAr[1])){
            $tmp = explode('/', $methodAr[1]);
            if(count($tmp)==2){
                $clsAr = explode('\\', $cls);
                $clsAr[count($clsAr)-1] = $tmp[0];
                $cls = implode('\\', $clsAr);
                $methodAr[1] = $tmp[1];
            }
        }
        $controller = new $cls;

        if($controller instanceof Controller){
            $this->propagateScopes($controller);
        }

        if($controller instanceof Controller && is_array($methodAr) &&
            isset($methodAr[0]) && isset($methodAr[1]))
        {
            /* @var $controller Controller */

            $actionName = $methodAr[1];
            $result = $controller->run(
                $actionName,
                [new ParameterDictionary($allParams)]
            );
            $this->addErrors($controller->getErrors());
            if($result instanceof \Bitrix\Main\HttpResponse){
                return $result;
            }elseif($result){
                return ['result'=>$result];
            }else{
                return null;
            }
        }elseif($controller instanceof \Bitrix\Main\Engine\Controller &&
            is_array($methodAr) && isset($methodAr[1]))
        {
            /* @var $controller \Bitrix\Main\Engine\Controller */

            $actionName = $methodAr[1];
            $action = $controller->create($actionName);
            $controller->setSourceParametersList([new ParameterDictionary($allParams)]);
            $result = $action->runWithSourceParametersList();
            $this->addErrors($action->getController()->getErrors());
            if($result instanceof \Bitrix\Main\HttpResponse){
                return $result;
            }elseif($result){
                return ['result'=>$result];
            }else{
                return null;
            }
        }else{
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_M_FORMAT_ERR').' '.$method));
            return null;
        }
    }

    public function callAction(int $app, string $method = '', array $order = [], array $select = [], array $filter = [], int $start = 0, string $id = "", array $fields = [], array $params = []){

        $binderResult = $this->bindCall($app, $method, $order, $select, $filter, $start, $id, $fields, $params);
        if(!$binderResult->isSuccess()){
            $this->addErrors($binderResult->getErrors());
            return null;
        }
        if(!empty($binderResult->getData())){
            return $binderResult->getData();
        }

        $method = $binderResult->getMethod();
        $id = $binderResult->getId();
        $order = $binderResult->getOrder();
        $select = $binderResult->getSelect();
        $filter = $binderResult->getFilter();
        $start = $binderResult->getStart();
        $fields = $binderResult->getFields();
        $params = $binderResult->getParams();

        $allParams = $this->getRequest()->getValues();
        foreach($params as $c=>$v){
            $allParams[$c] = $v;
        }

        $methodsParams = $this->resolveMethodParams();
        if(!$methodsParams){
            return null;
        }

        if(!$this->checkCallParams($method, $methodsParams, $params)){
            return null;
        }

        //контроллер
        if($this->isControllerEntity($methodsParams['ENTITY'])){
            return $this->dispatchToController($method, $methodsParams, $allParams);
        }

        //не orm
        $cls = $methodsParams['ENTITY'];
        if(!($cls && is_string($cls) && class_exists($cls) && method_exists($cls, 'getEntity'))){
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_PARAM_M_ERR')));
            return null;
        }

        //ORM-методы с предустановленными параметрами: fields/add/list/get/update/delete
        return $this->ormDispatch($method, $methodsParams, $binderResult);
    }

    /**
     * ORM-методы с предустановленными параметрами: fields/add/list/get/update/delete
     */
    protected function ormDispatch(string $method, array $methodsParams, BinderResult $binderResult)
    {
        $id = $binderResult->getId();
        $order = $binderResult->getOrder();
        $select = $binderResult->getSelect();
        $filter = $binderResult->getFilter();
        $start = $binderResult->getStart();
        $fields = $binderResult->getFields();

        $methodAr = explode('.',$method);
        if(is_array($methodAr) && isset($methodAr[1])){

            $methodName = $methodAr[1];
            $appName = $methodAr[0];

            $entityClass = $methodsParams['ENTITY'];
            if(!class_exists($entityClass)){
                $this->addError(new Error($appName.' - '.Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_CL_NOT_FOUND')));
                return null;
            }

            if($methodName === 'fields'){
                return $this->getFields($entityClass, $methodsParams['PARAMS']['fields']);
            }

            if($methodName === 'add'){
                return $this->add($entityClass, $methodsParams['PARAMS']['fields'], $fields);
            }

            if($methodName === 'list'){
                return $this->getList($entityClass, $methodsParams['PARAMS']['fields'],
                    [
                        'order'=>$order,
                        'select'=>$select,
                        'filter'=>$filter,
                        'start'=>$start,
                        'count_total'=>true
                    ]
                );
            }

            $primaryKey = 'ID';
            if(in_array($methodName, ['delete', 'get', 'update'])){
                if(!$id){
                    $this->addError(new Error('id is required'));
                    return null;
                }
                $primaryKey = '';
                foreach($methodsParams['PARAMS']['fields'] as $fieldKey=>$fieldValue){
                    if($fieldValue['isActive'] === 'Y' && $fieldValue['type']==='primary'){
                        if(mb_strpos($fieldKey, '.')!==false) continue;
                        $primaryKey = $fieldKey;
                        break;
                    }
                }
                if(!$primaryKey){
                    $this->addError(new Error('primary key not active'));
                    return null;
                }
            }
            if($methodName === 'delete'){
                return $this->delete($entityClass, $methodsParams['PARAMS']['fields'], [$primaryKey=>$id]);
            }
            if($methodName === 'update'){
                return $this->update($entityClass, $methodsParams['PARAMS']['fields'], [$primaryKey=>$id], $fields);
            }
            if($methodName === 'get'){
                $result = $this->getList($entityClass, $methodsParams['PARAMS']['fields'],
                    ['filter'=>['='.$primaryKey=>$id], 'limit'=>1, 'count_total'=>false]
                );
                if(isset($result['result']['items'][0])){
                    return [
                        'result'=> [
                            'item'=>$result['result']['items'][0]
                        ]
                    ];
                }else{
                    return [
                        'result'=> []
                    ];
                }
            }

            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_M_FORMAT_ERR').' '.$method));
            return null;

        }else{
            $this->addError(new Error(Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_M_FORMAT_ERR').' '.$method));
            return null;
        }
    }

    public function add(string $entityClass, array $fieldsParams, array $fields = []){
        $primaryKey = '';
        foreach($fieldsParams as $fieldKey=>$fieldValue){
            if($fieldValue['isActive'] === 'Y' && $fieldValue['type']==='primary'){
                if(mb_strpos($fieldKey, '.')!==false) continue;
                $primaryKey = $fieldKey;
                break;
            }
        }
        if(!$primaryKey){
            $this->addError(new Error('primary key not active'));
            return null;
        }

        $activeFields = [];
        foreach($fieldsParams as $fieldKey=>$fieldValue){
            if($fieldValue['isActive'] === 'Y'){
                $activeFields[] = $fieldKey;
            }
        }
        $fieldsFin = [];
        foreach($fields as $k=>$v){
            if(in_array($k, $activeFields)){
                $fieldsFin[$k] = $v;
            }
        }
        $res = $entityClass::add($fieldsFin);
        if(!$res->isSuccess()){
            foreach($res->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }
        return [
            'result'=>$res->getId()
        ];
    }

    public function update(string $entityClass, array $fieldsParams, array $primary, array $fields = []){
        $activeFields = [];
        foreach($fieldsParams as $fieldKey=>$fieldValue){
            if($fieldValue['isActive'] === 'Y' && $fieldValue['isReadOnly'] !== 'Y'){
                if(mb_strpos($fieldKey, '.')!==false) continue;
                $activeFields[] = $fieldKey;
            }
        }

        $fieldsFin = [];
        foreach($fields as $k=>$v){
            if(in_array($k, $activeFields)){
                $fieldsFin[$k] = $v;
            }
        }

        $res = $entityClass::update($primary, $fieldsFin);
        if(!$res->isSuccess()){
            foreach($res->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }
        return [
            'result'=>true
        ];
    }

    public function delete(string $entityClass, array $fieldsParams, array $primary){
        /* @var $entityClass \Bitrix\Main\ORM\Data\DataManager */
        $res = $entityClass::delete($primary);
        if(!$res->isSuccess()){
            foreach($res->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }
        return [
            'result'=>true
        ];
    }

    public function getList(string $entityClass, array $fieldsParams, array $params){

        $limit = 50;
        $offset = ceil($params['start']/$limit)*$limit;

        $activeFields = [];
        $sortableFields = [];
        $defSort = [];
        foreach($fieldsParams as $fieldKey=>$fieldValue){
            if($fieldValue['isActive'] === 'Y'){
                $activeFields[] = $fieldKey;
                if($fieldValue['type']==='primary'){
                    $defSort = [$fieldKey => 'asc'];
                }
                if($fieldValue['isSortable'] == 'Y'){
                    $sortableFields[] = $fieldKey;
                }
            }
        }

        if(!isset($params['select']) || empty($params['select'])){
            $query['select'] = $activeFields;
        }else{
            $query['select'] = [];
            foreach($params['select'] as $k){
                if(in_array($k, $activeFields)){
                    $query['select'][] = $k;
                }
            }
        }
        $selectFormat = [];
        foreach($query['select'] as $k){
            if(mb_strpos($k,'.')!==false){
                $kvAr = explode('.',$k);
                if(count($kvAr)<=3){
                    $selectFormat[str_replace('.','___',$k)] = $k;
                }
            }else{
                $selectFormat[$k] = $k;
            }
        }
        $query['select'] = $selectFormat;

        $query['order'] = $defSort;
        if(isset($params['order']) && is_array($params['order'])){
            $keyOrder = array_keys($params['order']);
            if(in_array($keyOrder[0], $sortableFields)){
                $query['order'] = $params['order'];
            }
        }
        if(isset($params['filter']) && is_array($params['filter'])){
            $query['filter'] = $params['filter'];
        }

        if(!isset($params['limit'])){
            $query['limit'] = $limit;
        }else{
            $query['limit'] = $params['limit'];
        }
        $query['offset'] = $offset;
        if(!isset($params['count_total'])){
            $query['count_total'] = true;
        }else{
            $query['count_total'] = ($params['count_total'] === true);
        }
        $res = $entityClass::getList($query);

        $finData = [
            'result'=> [
                'items'=>$res->fetchAll(),
            ],
        ];
        if($query['count_total']){
            $finData['total'] = $res->getCount();
            $next = $query['limit'] + $offset;
            if($next<$finData['total']){
                $finData['next'] = $next;
            }
        }

        return $finData;
    }

    public function getFieldsFormat($field, array $fieldsParams = [], $lv1 = null, $lv2 = null){
        /*
             * [type] => string
             * [isRequired] => 1
             * [isReadOnly] =>
             * [isImmutable] =>
             * [isMultiple] =>
             * [isDynamic] =>
             * [title] => Имя
             * */

        $fieldCode = $field->getName();
        if($lv1){
            $fieldCode = $lv1->getName().'.'.$field->getName();
            if($lv2){
                $fieldCode = $lv1->getName().'.'.$lv2->getName().'.'.$field->getName();
            }
        }

        $noLink = false;
        if($fieldsParams[$fieldCode]['type'] === 'primary'){
            $fieldsParams[$fieldCode]['type'] = 'integer';
            if($field instanceof \Bitrix\Main\ORM\Fields\StringField){
                $fieldsParams[$fieldCode]['type'] = 'string';
            }
            $noLink = true;
        }
        $fieldsData[$fieldCode] = [
            'type'=>$fieldsParams[$fieldCode]['type'],
            'isRequired'=>$fieldsParams[$fieldCode]['isRequired'] == 'Y' ? 1 : 0,
            'isReadOnly'=>$fieldsParams[$fieldCode]['isReadonly'] == 'Y' ? 1 : 0,
            'sort'=>$fieldsParams[$fieldCode]['isSortable'] == 'Y' ? $fieldCode : '',
            'title'=>$fieldsParams[$fieldCode]['title'],
            'noLink'=>1
        ];
        if($field instanceof \Bitrix\Main\ORM\Fields\EnumField){
            $values = $field->getValues();
            if(is_array($values)){
                $valuesKeys = array_keys($values);
                $newValues = [];
                if(isset($valuesKeys[count($values)-1])){
                    foreach($values as $v){
                        $newValues[$v] = $v;
                    }
                    $values = $newValues;
                }
                $fieldsData[$fieldCode]['values'] = $values;
            }
        }
        if($field instanceof \Bitrix\Main\ORM\Fields\BooleanField){
            $values = $field->getValues();
            if(is_array($values)){
                $valuesKeys = array_keys($values);
                $newValues = [];
                if(isset($valuesKeys[count($values)-1])){
                    foreach($values as $v){
                        $langVal = Loc::getMessage('AWZ_BXORM_API_CONTROLLER_HOOK_BOOL_FIELD_'.$v);
                        $newValues[$v] = $langVal ? $langVal : $v;
                    }
                    $values = $newValues;
                }
                $fieldsData[$fieldCode]['values'] = $values;
            }
        }
        if($noLink){
            $fieldsData[$fieldCode]['noLink'] = 1;
        }
        return $fieldsData[$fieldCode];
    }

    public function getFields(string $entityClass, array $fieldsParams = []){
        $fieldsData = [];
        $fields = $entityClass::getMap();
        /* @var $field \Bitrix\Main\ORM\Fields\Field */
        foreach($fields as $field){
            //FIELD_PARAMS[fields][PARAMS][isActive]
            if($field instanceof \Bitrix\Main\ORM\Fields\Relations\Reference){
                if($field->getRefEntity()) {
                    foreach ($field->getRefEntity()->getFields() as $rel1Field) {
                        if($rel1Field instanceof \Bitrix\Main\ORM\Fields\Relations\Reference){
                            if($rel1Field->getRefEntity()) {
                                foreach ($rel1Field->getRefEntity()->getFields() as $rel2Field) {
                                    if($rel2Field instanceof \Bitrix\Main\ORM\Fields\Relations\Reference){

                                    }else{
                                        $fieldCode = $field->getName().'.'.$rel1Field->getName().'.'.$rel2Field->getName();
                                        if(!isset($fieldsParams[$fieldCode]['isActive'])) continue;
                                        if($fieldsParams[$fieldCode]['isActive']!='Y') continue;
                                        $formatedFields = $this->getFieldsFormat($rel2Field, $fieldsParams, $field, $rel1Field);
                                        if($formatedFields && is_array($formatedFields)){
                                            $fieldsData[$fieldCode] = $formatedFields;
                                        }
                                    }
                                }
                            }
                        }else{
                            $fieldCode = $field->getName().'.'.$rel1Field->getName();
                            if(!isset($fieldsParams[$fieldCode]['isActive'])) continue;
                            if($fieldsParams[$fieldCode]['isActive']!='Y') continue;
                            $formatedFields = $this->getFieldsFormat($rel1Field, $fieldsParams, $field);
                            if($formatedFields && is_array($formatedFields)){
                                $fieldsData[$fieldCode] = $formatedFields;
                            }
                        }
                    }
                }
            }else{
                $fieldCode = $field->getName();
                if(!isset($fieldsParams[$fieldCode]['isActive'])) continue;
                if($fieldsParams[$fieldCode]['isActive']!='Y') continue;
                $formatedFields = $this->getFieldsFormat($field, $fieldsParams);
                if($formatedFields && is_array($formatedFields)){
                    $fieldsData[$fieldCode] = $formatedFields;
                }
            }
        }
        return [
            'result'=>$fieldsData
        ];
    }

    public function batchAction(int $app, array $cmd = [], bool $halt = false){

        $actionsResult = [];
        $actionsErrors = [];

        $startRequest = $this->getRequest();
        $requestValuesStart = $this->getRequest()->getValues();
        unset($requestValuesStart['cmd']);
        unset($requestValuesStart['action']);

        foreach($cmd as $key=>$row){
            $rowAr = explode("?", $row);
            parse_str($rowAr[1], $rowArQuery);
            $rowArQuery['method'] = $rowAr[0];
            $controller = new $this;
            $requestValues = $requestValuesStart;
            foreach($rowArQuery as $k=>$v){
                $requestValues[$k] = $v;
            }
            $requestValues['action'] = 'awz:bxorm.api.app.call';
            $controller->request = $startRequest;
            $controller->request->addFilter(new ReplaceFilter($requestValues));
            $result = $controller->run(
                'call',
                [new ParameterDictionary($requestValues)]
            );
            $actionsResult[$key] = $result['result'];
            if($controller->getErrors()){
                $actionsErrors[$key] = [];
                foreach($controller->getErrors() as $err){
                    $actionsErrors[$key][] = ['code'=>$err->getCode(), 'message'=>$err->getMessage()];
                }
            }
        }
        return [
            'result'=>$actionsResult,
            'result_error'=>$actionsErrors
        ];

    }

    public function methodsAction(int $app){

        $methodNames = Helper::getMethods(HooksTable::getEntity());

        $hookData = HooksTable::getRowById($app);
        $methods = [];
        $methodsList = [];
        if(!empty($hookData['METHODS'])){
            foreach($hookData['METHODS'] as $methodId){
                $methods[] = $methodId;
            }
        }

        if(!empty($methods)){
            $methodsParamsRes = MethodsTable::getList([
                'select'=>['*'],
                'filter'=>['=ID'=>$methods, '=ACTIVE'=>'Y']
            ]);
            while($data = $methodsParamsRes->fetch()){
                $methodsList[$data['CODE']] = $data['NAME'];
                if(!empty($data['PARAMS']['methods'])){
                    foreach($data['PARAMS']['methods'] as $code=>$active){
                        if($active==='Y'){
                            $addName = $data['NAME'];
                            if(isset($methodNames[$code])){
                                $addName .= ' - '.$methodNames[$code];
                            }else{
                                $addName .= ' - '.$code;
                            }
                            $methodsList[$data['CODE'].'.'.$code] = $addName;
                        }
                    }
                }

            }
        }

        return [
            'methods'=> $methodsList
        ];
    }

    public function finalizeResponse(Response $response): void
    {
        //формат ответа не переопределяется — компактный лог в одну строку из Scopes\Controller::finalizeResponse
        parent::finalizeResponse($response);
    }

    /**
     * Возвращает массив с данными запроса в формате JSON-RPC
     *
     * @return array|null
     */
    protected function getJsonRequestData(): ?array
    {
        $rawInput = $this->getRequest()->getInput();
        if (!$rawInput) {
            return null;
        }

        try {
            return Json::decode($rawInput);
        } catch (\Exception $e) {
            return null;
        }
    }

}
