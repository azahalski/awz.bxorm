<?php
namespace Awz\BxOrm\Api\Filters\Request;

use Bitrix\Iblock\ORM\Loader;
use Bitrix\Main\Type;
use Bitrix\Main\Web\Json;

class Rpc implements Type\IRequestFilter
{
    protected $input = "";

    public function __construct(string $input)
    {
        $this->input = $input;
    }

    public function getInput(): string
    {
        return (string) $this->input;
    }

    public function getInputJson(): array
    {
        $inpStr = $this->getInput();
        if(!$inpStr) return [];

        return Json::decode($inpStr);
    }

    /**
     * @param array $values
     * @return array
     */
    public function filter(array $values)
    {
        $json = $this->getInputJson();
        if(isset($json['method']) && $json['method']){
            $values['get']['method'] = $json['method'];
            $values['post']['method'] = $json['method'];
        }

        $values['get']['json'] = $json;
        $values['post']['json'] = $json;

        return $values;
    }


}