<?php

namespace Tests\Local\Lib\DTO\Fixtures;

use Local\Lib\DTO\BaseDTO;

/**
 * Класс для тестирования экспортера
 */
class ExportTestDTO extends BaseDTO
{
    /**
     * @var int Внутренний ID
     */
    public int $id;

    public ?string $status = 'active';

    public array $roles = [];
}