<?php

namespace awz\bxorm\api\controller\mcp\DTO\Tool;

use awz\bxorm\api\controller\mcp\Traits\JsonSerializable;

/**
 * DTO для элемента списка инструментов.
 */
class ToolListItem implements \JsonSerializable
{
    use JsonSerializable;

    /**
     * @param string $name Название инструмента
     * @param string $description Описание инструмента
     * @param InputSchema $inputSchema Схема входных параметров
     * @param OutputSchema|null $outputSchema Схема выходных параметров
     */
    public function __construct(
        readonly public string        $name,
        readonly public string        $description,
        readonly public InputSchema   $inputSchema,
        readonly public ?OutputSchema $outputSchema = null,
    )
    {
    }
}
