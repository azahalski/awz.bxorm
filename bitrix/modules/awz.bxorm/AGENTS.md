# awz.bxorm — агентские заметки (AGENTS.md)

Модуль `awz.bxorm` (автор: zahalski.dev, версия 1.0.11) — конструктор HTTP-API и MCP-сервер поверх Bitrix ORM-сущностей и контроллеров. Через админку описываются «методы» (обёртки над ORM-сущностями или контроллерами), собираются в «хуки» (приложения с токеном) и отдаются наружу тремя транспортами: `hook.call` (webhook, стандартные ограничения), `app.call` (webhook, любые параметры), `bxmcp.callrpc` (MCP JSON-RPC 2.0).

## Структура

```
.settings.php            — регистрация namespace контроллеров: \Awz\BxOrm\Api\Controller => префикс 'api'
include.php              — пустой
install/                 — index.php (инсталлятор), db/mysql/*.sql (b_awz_bxorm_methods, b_awz_bxorm_hooks), admin/ (страницы админки)
lang/ru/                 — языковые файлы (зеркалят структуру lib/)
admin/                   — точки входа админки: hooks_list/edit, methods_list/edit, menu.php (Настройки → awz.bxorm)
lib/
  methods.php            — MethodsTable (b_awz_bxorm_methods)
  hooks.php              — HooksTable (b_awz_bxorm_hooks)
  helper.php             — Helper: getMethods(), replaceMethod(), generateToken(), createMcpApp(), deleteMcpApp(), getMcpMethodId(), getMcpHook(), getMcpActions(), getMcpUrl()
  adminpages/            — админ-страницы (Awz\Admin\IForm/IList); HooksList рисует ссылки методов: ORM-методы → hook.call, контроллеры → app.call
  api/
    scopes/              — Controller (базовый: логгер logLine/requestId, scopes), Scope, ScopeCollection, Parameters, BaseFilter
    filters/             — AppAuth (авторизация app+key), CheckMethod (доступ к методу), RpcCheck, NoCors, Sign
    filters/request/     — Rpc (JSON-RPC тело → параметры запроса), SetFilter, ReplaceFilter (для batch)
    binderresult.php     — контейнер binder'а (app/method/order/select/filter/fields/params)
    controller/
      app.php            — App extends Scopes\Controller: универсальный транспорт (call/batch/methods, awz:bxorm.api.app.*) — принимает и контроллеры, и ORM-методы (тип определяет ENTITY в MethodsTable), в call можно передавать любые параметры (id, filter и т.д.); общая реализация ORM-методов (ormDispatch + add/update/delete/getList/getFields); хелперы bindCall/resolveMethodParams/dispatchToController/propagateScopes; точка расширения checkCallParams (база — любые параметры)
      bxmcp.php          — BxMcp extends App: MCP-транспорт (callrpc JSON-RPC 2.0, валидация запроса, finalizeMcpResponse)
      hook.php           — Hook extends App: стандартный webhook-транспорт — те же методы, но checkCallParams запрещает зарезервированные ключи params (кроме MCP-сущности) и требует POST для мутирующих MCP-действий
      mcp/               — MCP-сервер: App (initialize/ping), Tools (tools/list, tools/call, 5 встроенных инструментов), Resources (list/read), Prompts (list/get), DTO
      partner/           — orders, modules (служебные)
options.php              — настройки: вкладка «MCP для ИИ» (версии сервера/протокола — опции, кнопки Создать/Удалить MCP, URL, токен, ссылки на методы/хуки), права доступа
```

## Данные

- `MethodsTable` (`b_awz_bxorm_methods`): `NAME`, `CODE` (префикс метода, напр. `ib`), `ENTITY` (ORM-класс или класс контроллера), `MODULES` (список модулей для Loader), `ACTIVE`, `PARAMS` (serialize):
  - `PARAMS['methods']` — карта доступных действий `[кодДействия => Y/N]`;
  - `PARAMS['fields']` — для ORM-сущностей: `[поле => ['isActive','isRequired','isReadOnly','type','title',...]]`.
- `HooksTable` (`b_awz_bxorm_hooks`): `NAME`, `TOKEN` (ключ доступа), `ACTIVE`, `METHODS` (serialize — список строк вида `METHOD_ID` («все действия») или `METHOD_ID.действие`).

## Эндпоинты

Всё идёт через `bitrix/services/main/ajax.php` (actions namespace из модульного `.settings.php`):

- `action=awz:bxorm.api.hook.call&app=ID&key=TOKEN&method=CODE.action` — стандартный webhook: ORM (fields/add/list/get/update/delete) и контроллеры; запрещенные ключи в `params`.
- `action=awz:bxorm.api.app.call&app=ID&key=TOKEN&method=CODE.action` — универсальный: те же методы, но любые параметры в `params` (для своих контроллеров).
- `action=awz:bxorm.api.hook.methods&app=ID&key=TOKEN` — список доступных методов.
- `action=awz:bxorm.api.hook.batch` — пакетный вызов (cmd).
- `action=awz:bxorm.api.bxmcp.callrpc&app=ID&mcprpc=2.0` — **MCP-сервер** (JSON-RPC 2.0). Старый `hook.callrpc` не поддерживается.

Авторизация: фильтр `AppAuth` (lib/api/filters/appauth.php) принимает `key` в GET/POST или заголовок `Authorization: Token/Bearer`. Ключ = `TOKEN` хука; формат `userId|TOKEN` дополнительно авторизует пользователя (`$USER->Authorize`). Битый/чужой ключ → HTTP 401 (`err_auth`).

`CheckMethod` (lib/api/filters/checkmethod.php) сверяет пару `CODE.действие` с `HooksTable::METHODS` и `MethodsTable::PARAMS['methods']`; для MCP-сущности протокольные действия (`initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get` — константа `CheckMethod::MCP_PROTO_ACTIONS`) доступны всегда, если хук содержит метод. Гейтинг конкретных инструментов — в `Mcp\Tools::callAction` (`checkToolEnabled`). Подстановка `params.name` в путь метода **не выполняется нигде** — `tools/call` всегда уходит в `Mcp\Tools::callAction` (кастомные инструменты — событие `onMcpToolCall`, встроенные — `checkToolEnabled`, неизвестные — `isError`).

## MCP-сервер: как устроен

Классы: `lib/api/controller/mcp/` — `App` (`initialize`, `ping`), `Tools` (`tools/list`, `tools/call` + встроенные инструменты envinfo/phpinfo/cmsinfo/dbinfo/processinfo/log_get/log_set/file_get — методы называются по коду действия: `log_getAction`, `log_setAction`, `file_getAction`), `Resources` (list/read), `Prompts` (list/get).

- Протокол: `App::$MCP_PROTO_VERSION = '2025-06-18'` (public static, дефолт), сервер `App::$MCP_SERVER_VERSION = '1.4.0'` (public static, дефолт). Фактические значения — опции модуля `mcp_proto_version`/`mcp_server_version` (Настройки → MCP для ИИ), геттеры `getProtoVersion()`/`getMcpServerVersion()` возвращают значение опции либо дефолт из кода, если опция пуста.
- `initialize`: отсутствует `params.protocolVersion` → `-32602`; при несовпадении версий по спеке возвращает **свою** последнюю версию в `result.protocolVersion` (решение о совместимости за клиентом), отказ `-32000` не делается. Capabilities объявляются только реализованные: `tools`/`prompts`/`resources`.
- Валидация JSON-RPC в `BxMcp::callrpcAction`: не-объект/batch-массив, `jsonrpc != "2.0"`, `method` не строка, `params` не объект → `-32600`; некорректный `id` (не string/int/float) → `-32602`; без `mcprpc=2.0` → `-32600`; POST-only. Notifications (`notifications/*`, без `id`) не порождают ответа — `finalizeMcpResponse` отдаёт `202 Accepted` с пустым телом.
- Коды ошибок JSON-RPC: `-32601` метод не найден (CheckMethod), `-32602` неверные параметры/не найден ресурс/промпт, `-32600` невалидный запрос, `-32603` прочее; специфичный отрицательный код из `Error` прокидывается в ответ (`finalizeMcpResponse`), ошибки `err_auth` → HTTP 401.
- `BxMcp::callrpcAction` (`App::dispatchToController`) пробрасывает scopes в создаваемый sub-контроллер (`propagateScopes`/`addScopeApi`) — `Tools::getScopeMethodId()`/`checkToolEnabled()` полагаются на scope `method` (METHOD_ID). Без проброса инструменты вернут `isError`.
- Инструменты: вызов — `tools/call` с `params.name` (+`params.arguments`), имя валидируется в `Mcp\Tools::callAction` (`[0-9a-zA-Z_]`, нельзя `call`/`list`); аргументы мержатся в параметры action'а. `tools/list` — встроенные 8 инструментов (envinfo/phpinfo/cmsinfo/dbinfo/processinfo/log_get/log_set/file_get; схемы параметров в `listAction` → `$schemas`) + инструменты из события `onBuildMcpTools`.
- Встроенные log/file-инструменты: `log_get` — хвост лог-файла (`type` default `exception_handling` — файл из `.settings.php` секции `log`; либо `type`=имя PSR-логгера — файл из `constructorParams` логгера секции `loggers` (только `\Bitrix\Main\Diag\FileLogger`); параметр `file` пользователь не передает; `lines` 1-1000, чтение хвостом до 200 КБ); `log_set` — настройка лога (`file` обязателен, `size_mb` 1-512 default 20, `level` PSR-уровень default `debug`, `type` как в `log_get`): `exception_handling` — секция `log` (`Configuration::add('log')`: `file`, `log_error`, `log_file_size`; файл НЕ создается — Bitrix сделает это при первой записи), имя логгера — только PSR-логгер в `loggers` (`\Bitrix\Main\Diag\FileLogger`, closure `constructorParams` → `[путь, размер]`); каталог файла: константа `AWZ_BXORM_LOG_PATH` → существующий `/local/logs/` → `/local/php_interface/` → `/bitrix/php_interface/`. `file_get` — контент файла (путь абсолютный/от корня, только внутри DOCUMENT_ROOT — realpath-проверка; blacklist служебных файлов `FILE_READ_BLACKLIST` (dbconn.php, .settings*.php, .access.php, *.env*, after_connect*) и каталогов `FILE_READ_BLACKLIST_DIRS` (backup); whitelist-каталоги — опция `mcp_file_whitelist` (default `/bitrix/php_interface/,/bitrix/modules/,/local/,/upload/`); `lines`: номер/диапазон/список "1,5,9-12", одиночная строка с контекстом ±3; до 300 КБ / 1000 строк с пометкой обрезки). Это admin-grade инструменты — доступ по токену хука; сравнение токенов — `hash_equals` (constant-time, `Helper::checkServiceKey`).
- Ресурсы/промпты: списки (`resources/list`, `prompts/list`) и чтение (`resources/read` по `params.uri`, `prompts/get` по `params.name`) наполняются событиями сайта.

## Автоматическое создание MCP-приложения

`Настройки → awz.bxorm → MCP для ИИ`:
- **Создать MCP** — `Helper::createMcpApp()`: создает/обновляет метод (`CODE='mcp'`, `ENTITY=\Awz\BxOrm\Api\Controller\Mcp\App`, PARAMS.methods = `Helper::getMcpActions()`) и хук «MCP AI (авто)» с токеном; повторное нажатие обновляет токен и список действий. Показывает URL (без ключа), токен, готовый URL с ключом и пример заголовка Authorization.
- **Удалить MCP** — `Helper::deleteMcpApp()`: удаляет хук «MCP AI (авто)» и метод.
- Версии (сервер/протокол) — опции `mcp_server_version`/`mcp_proto_version`, пусто = дефолт из кода.

## Как добавить встроенный MCP-инструмент (в код модуля)

1. **Реализация**: в `Tools` (lib/api/controller/mcp/tools.php) добавить `tools/<код>` в `App::configureActions()` (prefilters NoCors+AppAuth) и метод `<Код>Action(): array`, возвращающий MCP-контент: `return ['content' => [["type"=>"text","text"=>"..."]]]`.
2. **Документация (схема)**: в `Tools::listAction()` — карта `$descriptions` (name => описание для LLM) + при параметрах `PropertySchema` в `InputSchema`.
3. **Включение**: админка → Методы → запись с `ENTITY = \Awz\BxOrm\Api\Controller\Mcp\App` (пересоздание «Создать MCP» обновляет список автоматически), включить действие `tools/<код>` в PARAMS; хук должен содержать метод. Без включения — `checkToolEnabled` вернет `isError`.
4. Поднять `App::$MCP_SERVER_VERSION`.

Кастомные (сайт-специфичные) инструменты кодом модуля НЕ добавляются — используйте события (следующий раздел) или отдельный MCP-контроллер сайта.

## События (точки расширения)

- `awz.bxorm::onBeforeGetMethods` — дополнить список методов для сущности (Helper::getMethods).
- `awz.bxorm::onBeforeHookCallAction` — перехват/подмена вызова до binder'а (через `BinderResult` можно вернуть данные или ошибку).
- `awz.bxorm::showField` — доп. поля в админ-форме метода (колбэк `$func($form, $arField)`).
- `awz.bxorm::onBuildMcpTools` (params: `app`, `method_id`, `tools`) — дополнить `tools/list`, вернуть `['tools'=>ToolListItem[]]` (DTO: `awz\bxorm\api\controller\mcp\DTO\Tool\`, namespace lowercase).
- `awz.bxorm::onMcpToolCall` (params: `app`, `method_id`, `name`, `arguments`) — кастомный tool call, вызывается до встроенных; вернуть `['result'=>['content'=>[...], 'isError'=>...]]`.
- `awz.bxorm::onBuildMcpResources` (params: `app`, `resources`) — дополнить `resources/list`: `['resources'=>[{uri,name,description?,mimeType?}]]`.
- `awz.bxorm::onReadMcpResource` (params: `app`, `uri`) — содержимое для `resources/read`: `['resource'=>['uri','mimeType','text']]`.
- `awz.bxorm::onBuildMcpPrompts` (params: `app`, `prompts`) — дополнить `prompts/list`: `['prompts'=>[{name,description?,arguments?}]]`.
- `awz.bxorm::onGetMcpPrompt` (params: `app`, `name`, `arguments`) — содержимое для `prompts/get`: `['prompt'=>['description','messages'=>[{role,content:{type:'text',text}}]]]`.

## Расширение методами сайта (без правки модуля)

Модуль обновляемый — сайт-специфичные методы добавляются из `bitrix/php_interface/init.php` (или `local/modules/<site-module>`). Рабочий пример MCP-событий (ресурс `site://settings`, промпт `site_audit`, закомментированный инструмент `site_stats`): `bitrix/php_interface/include/awz_bxorm_mcp.php` (подключен из init.php **до** `return;`; в раннем контексте ядра `EventManager::getInstance()` возвращает null — файл защищен выходом).

Ошибки обработчиков событий оборачивать в try/catch и возвращать `isError`-результат — иначе клиент получит envelope-ошибку `-32603` вместо текста.

### 1. MCP-инструмент через события (основной способ)

Имя инструмента namespac'ить (`site_*`, `log_*`), чтобы не пересекаться со встроенными — встроенные проверяются после события и не переопределяются. Пример:

```php
$eventManager = \Bitrix\Main\EventManager::getInstance();
$eventManager->addEventHandler('awz.bxorm', 'onBuildMcpTools', function($event){
    $event->addResult(new \Bitrix\Main\EventResult(
        \Bitrix\Main\EventResult::SUCCESS,
        ['tools' => [new \awz\bxorm\api\controller\mcp\DTO\Tool\ToolListItem(
            name: 'site_stats',
            description: 'Статистика сайта за период',
            inputSchema: new \awz\bxorm\api\controller\mcp\DTO\Tool\InputSchema(
                type: 'object',
                properties: ['days' => new \awz\bxorm\api\controller\mcp\DTO\Tool\PropertySchema(
                    type: 'string', description: 'Период, дней'
                )],
                required: [],
            )
        )]]
    ));
});
$eventManager->addEventHandler('awz.bxorm', 'onMcpToolCall', function($event){
    if($event->getParameter('name') !== 'site_stats') return;
    $days = (int)(($event->getParameter('arguments') ?? [])['days'] ?? 7);
    $event->addResult(new \Bitrix\Main\EventResult(
        \Bitrix\Main\EventResult::SUCCESS,
        ['result' => ['content' => [['type'=>'text','text'=>'Статистика за '.$days.' дн.: ...']]]]
    ));
});
```

### 2. Метод-контроллер сайта

Свой класс с `listNameActions()` (например `Local\Api\Controller\Delivery::costAction()`):

```php
namespace Local\Api\Controller;
use Awz\BxOrm\Api\Scopes\Controller;
use Awz\BxOrm\Api\Scopes\Scope;

class Delivery extends Controller {
    public function configureActions(){
        return ['cost' => ['prefilters' => [new \Awz\BxOrm\Api\Filters\AppAuth(
            [], [], Scope::createFromCode('user'), []
        )]]] + parent::configureActions();
    }
    public function costAction(int $app, array $params = []){
        if(empty($params['city'])){ $this->addError(new \Bitrix\Main\Error('city required')); return null; }
        return ['result' => ['price' => 100]];
    }
}
```

Админка: Методы → `CODE=delivery`, `ENTITY=\Local\Api\Controller\Delivery`, включить действие `cost`; добавить метод в Хук. Вызов: `app.call&app=ID&key=TOKEN&method=delivery.cost` (любые параметры) или `hook.call` (со стандартными ограничениями).

Обычный `Bitrix\Main\Engine\Controller` тоже поддерживается (scopes ему не пробрасываются).

### 3. Метод над ORM-сущностью (без кода)

Методы → запись: `CODE` (префикс, напр. `hblog`), `ENTITY=\Local\Orm\HistoryTable`, `MODULES`, в PARAMS включить действия и поля. Вызов: `hook.call&method=hblog.list&filter[UF_CODE]=x` + `order`/`select`/`start`/`id`/`fields`. Для MCP инструменты над ORM создаются через события `onMcpToolCall` (свой `list` с фильтром) либо контроллер из п.2 с событием `onBuildMcpTools`.

### 4. Отдельный MCP-контроллер сайта (escape hatch)

Если нужно переопределить весь MCP-слой (свои Resources/Prompts/`initialize`): `local/modules/<site-module>/` с классами по образцу `Awz\BxOrm\Api\Controller\Mcp\*` (наследники `Awz\BxOrm\Api\Scopes\Controller`), `ENTITY` метода — на свой `App`-класс. Диспетчер `App::dispatchToController` подставляет под-контроллер по namespace: `\Local\Mcp\Api\Controller\App` + `tools/call` → `\Local\Mcp\Api\Controller\Tools`. `Tools::configureActions` (`CODE_REPLACE`), `listAction`, `callAction` повторить по образцу базовых.

Зарезервированные ключи `params` (`id`, `method`, `order`, `select`, `filter`, `fields`, `app`, `params`) запрещены в `hook.call` (кроме MCP-сущности) и разрешены в `app.call` — выбор транспорта за пользователем.

## Конвенции и подводные камни

- Коды действий MCP — только `[a-z0-9/]` (фильтры чистят метод `preg_replace('/[^0-9a-zA-Z.\/]/')`); точки — разделитель `CODE.action`, слэш — разделитель под-контроллер/action.
- `PARAMS`/`METHODS` сериализуются с `allowed_classes=false`; изменения схемы полей хранятся в БД, не в коде.
- `configureActions()` у MCP-классов вырезает префикс (`CODE_REPLACE`) из кодов родителя — action'ы доступны без префикса; `Tools::callAction` диспетчит по имени `<name>Action` (регистр важен).
- Ошибки инструмента — `isError` в result (не protocol-error): модель должна увидеть текст. Исключения обработчиков событий ловить в try/catch.
- Три транспорта — `hook.call` (стандартные ограничения params), `app.call` (любые параметры), `bxmcp.callrpc` (MCP). Тип метода (ORM/контроллер) определяет `ENTITY` записи — оба webhook-транспорта обрабатывают оба типа.
- Логгер: `Diag\Logger::create('awz.bxorm.<КороткоеИмяКласса>', [$this])` — ID в `.settings.php` → `loggers` указывается коротким именем класса (если ID не сконфигурирован, `getLogger()` вернет null и лог отключен). Все записи — компактно в одну строку с `\n` в конце через `Scopes\Controller::logLine()` (`{requestId} [tag] | {date} | key: value | ...`); массивы/объекты/трейсы сериализуются в json с `JSON_UNESCAPED_UNICODE` (`logValue()`, `compactTrace()`), requestId генерируется на `\Bitrix\Main\Security\Random::getString` и сквозной в рамках вызова.
- Языковые файлы: `lang/ru/...` зеркалят структуру `lib/`; новые строки добавлять туда, а не хардкодить (кроме текстов схем инструментов).
- `bitrix/php_interface/init.php` выполняется и в раннем контексте ядра (cron/агенты) — до `return;` подключать только код, устойчивый к отсутствию `$USER`/`$APPLICATION` (см. защитный выход в `awz_bxorm_mcp.php`).
