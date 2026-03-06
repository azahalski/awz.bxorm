<?php

namespace awz\bxorm\api\controller\mcp\Traits;

trait JsonSerializable
{
    /**
     * Убирает null-свойства из объектов
     *
     * @return object
     */
    public function jsonSerialize(): object
    {
        return (object)array_filter(get_object_vars($this), static function ($value) {
            return $value !== null;
        });
    }
}
