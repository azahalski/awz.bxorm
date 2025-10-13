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

class Modules extends Controller
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

        $apiMethod = 'marketplace.product.list';
        $PARTNER_ID = '13534132';
        //$PARTNER_ID = '863538';
        $PARTNER_CODE = 'u1A1xOlH19iOV3r9Jt5Ch4Het0Up';
        $params = [
            "action" => $apiMethod,
            "partnerId" => $PARTNER_ID,
            "auth" => md5($apiMethod."|".$PARTNER_ID."|".$PARTNER_CODE),
            "filter" => []
        ];
        /*if(isset($filter['ID']) && $filter['ID']){
            $params['filter']['id'] = intval($filter['ID']);
        }
        if(isset($filter['payed']) && $filter['payed']){
            $params['filter']['payed'] = $filter['payed'] == 'Y' ? 'Y' : 'N';
        }
        if(isset($filter['status']) && $filter['status']){
            $params['filter']['status'] = preg_replace('/([^A-Z])/','',$filter['status']);
        }*/
        if(isset($order['code']) && $order['code']){
            $params['order']['code'] = preg_replace('/([^a-z0-9.])/','',$order['code']);
        }elseif(isset($order['ID']) && $order['ID']){
            $params['order']['code'] = preg_replace('/([^a-z0-9.])/','',$order['ID']);
        }elseif(!empty($order)){
            $params['order'] = $order;
        }

        if(!empty($filter)){
            $params['filter'] = $filter;
        }

        $res = Helper::sendRequest(self::API_URL, $params);
        if($res->isSuccess()){
            $data = $res->getData();
            //echo'<pre>';print_r($data);echo'</pre>';
            //die();
            $arItems = [];
            foreach($data['result']['result']['list'] as $itm){
                $itm['ID'] = $itm['code'];
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
                'type'=>'string',
                'isRequired'=>1,
                'isReadOnly'=>1,
                'isAutoComplete'=>0,
                'isPrimary'=>1,
                'sort'=>'ID',
                'title'=>'ID модуля',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'code' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                'sort'=>'code',
                'title'=>'Код модуля',
                'noLink'=>1,
                //'noFilter'=>1
            ],
            'name' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'Название модуля',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'description' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'description',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'installationDescription' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'installationDescription',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'supportDescription' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'supportDescription',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'demoUrl' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'demoUrl',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'videoUrl' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'videoUrl',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'datePublish' => [
                'type'=>'string',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'code',
                'title'=>'datePublish',
                'noLink'=>1,
                'noFilter'=>1
            ],
            'priceRub' => [
                'type'=>'float',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'priceRub',
                'title'=>'Цена, RUB',
                'noLink'=>1,
                //'noFilter'=>1
            ],
            'modulePartnerId' => [
                'type'=>'integer',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'modulePartnerId',
                'title'=>'Партнер ID',
                'noLink'=>1,
                //'noFilter'=>1
            ],
            'partnerDiscount' => [
                'type'=>'boolean',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'Партнерская скидка',
                'noLink'=>1,
                'values'=> [
                    '0'=>'нет','1'=>'да'
                ]
            ],
            'isAdaptive' => [
                'type'=>'boolean',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'Адаптивный',
                'noLink'=>1,
                'values'=> [
                    '0'=>'нет','1'=>'да'
                ]
            ],
            'isComposite' => [
                'type'=>'boolean',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'Композитный',
                'noLink'=>1,
                'values'=> [
                    '0'=>'нет','1'=>'да'
                ]
            ],
            'hasOnlineDemo' => [
                'type'=>'boolean',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'Онлайн демо',
                'noLink'=>1,
                'values'=> [
                    '0'=>'нет','1'=>'да'
                ]
            ],
            'hasDemoVersion' => [
                'type'=>'boolean',
                'isRequired'=>0,
                'isReadOnly'=>1,
                //'sort'=>'N',
                'title'=>'Триал режим',
                'noLink'=>1,
                'values'=> [
                    '0'=>'нет','1'=>'да'
                ]
            ],
            /*
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
            ]*/
        ];

        return $fields;

    }
}