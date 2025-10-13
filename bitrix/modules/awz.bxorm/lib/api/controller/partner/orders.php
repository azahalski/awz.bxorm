<?php
namespace Awz\BxOrm\Api\Controller\Partner;

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

class Orders extends Controller
{
    const API_URL = 'https://partners.1c-bitrix.ru/rest/';

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
    }

    public function configureActions()
    {
        $config = [
            'fields' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ]
            ],
            'list' => [
                'prefilters' => [
                    new NoCors([],[]),
                    new AppAuth(
                        [], [],
                        Scope::createFromCode('user'),
                        []
                    )
                ]
            ],
        ];

        return $config;
    }

    public function listAction(int $app, string $method, array $order = [], array $select = [], array $filter = [], int $start = 0){

        $apiMethod = 'order.list';
        $PARTNER_ID = '13534132';
        //$PARTNER_ID = '863538';
        $PARTNER_CODE = 'u1A1xOlH19iOV3r9Jt5Ch4Het0Up';
        $params = [
            "action" => $apiMethod,
            "partnerId" => $PARTNER_ID,
            "auth" => md5($apiMethod."|".$PARTNER_ID."|".$PARTNER_CODE),
            "filter" => []
        ];
        if(isset($filter['ID']) && $filter['ID']){
            $params['filter']['id'] = intval($filter['ID']);
        }
        if(isset($filter['payed']) && $filter['payed']){
            $params['filter']['payed'] = $filter['payed'] == 'Y' ? 'Y' : 'N';
        }
        if(isset($filter['status']) && $filter['status']){
            $params['filter']['status'] = preg_replace('/([^A-Z])/','',$filter['status']);
        }
        $res = Helper::sendRequest(self::API_URL, $params);
        if($res->isSuccess()){
            $data = $res->getData();
            $arItems = [];
            foreach($data['result']['result']['list'] as $itm){
                $itm['ID'] = $itm['id'];
                $itm['basketDetail'] = [];
                foreach($itm['basket'] as $bRow){
                    $itm['basketDetail'][] = round($bRow['price'],2).' '.$itm['currency'].' x '.$bRow['quantity'].' - '.$bRow['name'];
                }
                $arItems[] = $itm;
            }
            $finData = [
                'items'=>$arItems
            ];
        }else{
            $finData = [
                'items'=>[],
            ];
        }

        return $finData;

    }

    public function fieldsAction(int $app, string $method){

        $fields = [
            'ID' => [
                'type'=>'integer',
                'isRequired'=>1,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'id заказа',
                'noLink'=>1
            ],
            'allowDelivery' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'allowDelivery',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'dateAllowDelivery' => [
                'type'=>'datetime',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'dateAllowDelivery',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'dateInsert' => [
                'type'=>'datetime',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'dateInsert',
                'noLink'=>1
            ],
            'datePayed' => [
                'type'=>'datePayed',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'datePayed',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'status' => [
                'type'=>'enum',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'status',
                'noLink'=>1,
                'values'=> [
                    'N' => 'принят', 'D' => 'отменен', 'F' => 'выполнен'
                ]
            ],
            'payed' => [
                'type'=>'char',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'payed',
                'noLink'=>1
            ],
            'price' => [
                'type'=>'float',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'price',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'currency' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'currency',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'contactPerson' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'contactPerson',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'paySystem' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'paySystem',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'inn' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'inn',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'b24Url' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'b24Url',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'regName' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'regName',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'regCompany' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'regCompany',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'basketDetail' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                'isMultiple'=>1,
                //'sort'=>'N',
                'title'=>'basketDetail',
                'noLink'=>1,
                'noFilter'=>1
            ]
        ];

        return $fields;

    }
}