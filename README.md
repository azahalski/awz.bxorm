# AWZ: Api на вебхуках

Описание

## 1. Установка

## 2. События модуля

**onBeforeHookCallAction**
позволяет изменить логику обработки action в контроллере

| Параметр                                     | Описание |
|----------------------------------------------|----------|
| `controller` `\Awz\BxOrm\Api\Controller\Hook` |          |

Изменение необходимо производить в `\Awz\BxOrm\Api\BinderResult` объекте

```php
//изменим параметр id

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;
use Bitrix\Main\EventResult;

EventManager::getInstance()->addEventHandler(
    'awz.bxorm',
    'onBeforeHookCallAction',
    array('handlersAwz','onBeforeHookCallAction')
);

class handlersAwz {

    public static function onBeforeHookCallAction(Event $event){
        
        $controller = $event->getParameter('controller');
        
        /* @var $params \Awz\BxOrm\Api\BinderResult */
        $params = $controller->getBinderResult();
        $id = preg_replace('/([^0-9])/','',$params->getId());
        
        $params->setId($id);
        
    }

}

```

```php
//вернем ошибку если ид меньше 100

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;

EventManager::getInstance()->addEventHandler(
    'awz.bxorm',
    'onBeforeHookCallAction',
    array('handlersAwz','onBeforeHookCallAction')
);

class handlersAwz {

    public static function onBeforeHookCallAction(Event $event){
        
        $controller = $event->getParameter('controller');
        
        /* @var $params \Awz\BxOrm\Api\BinderResult */
        $params = $controller->getBinderResult();
        $id = preg_replace('/([^0-9])/','',$params->getId());
        
        if($id < 100){
            $params->addError(
                new Error('ID не может быть меньше 100')
            );
        }
        
    }

}

//{"status":"error","data":null,"errors":[{"message":"ID не может быть меньше 100","code":0,"customData":null}]}
```

```php
//вернем произвольный ответ если id пустой

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;

EventManager::getInstance()->addEventHandler(
    'awz.bxorm',
    'onBeforeHookCallAction',
    array('handlersAwz','onBeforeHookCallAction')
);

class handlersAwz {

    public static function onBeforeHookCallAction(Event $event){
        
        $controller = $event->getParameter('controller');
        
        /* @var $params \Awz\BxOrm\Api\BinderResult */
        $params = $controller->getBinderResult();
        $id = preg_replace('/([^0-9])/','',$params->getId());
        
        if(!$id){
            $params->setData([
            'result'=>[
                'item'=>[
                   'ID'=>1, 
                   'NAME'=>'Default'
                   ]
                ]
            ]);
        }
        
    }

}

//{"status":"success","data":{"result":{"item":{"ID":1,"NAME":"Default"}}},"errors":[]}
```

<!-- cl-start -->
## История версий

https://github.com/zahalski/awz.bxorm/blob/master/CHANGELOG.md

<!-- cl-end -->
