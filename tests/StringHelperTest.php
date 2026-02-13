<?php

namespace Tests\Local\Lib\DTO;

use PHPUnit\Framework\TestCase;
use Local\Lib\DTO\Utils\StringHelper;

class StringHelperTest extends TestCase
{
    /**
     * @dataProvider camelToSnakeProvider
     */
    public function testCamelToSnake(string $input, string $expected): void
    {
        $this->assertSame($expected, StringHelper::camel2snake($input));
    }

    /**
     * @dataProvider snakeToCamelProvider
     */
    public function testSnakeToCamel(string $input, string $expected): void
    {
        $this->assertSame($expected, StringHelper::snake2camel($input));
    }

    public function camelToSnakeProvider(): array
    {
        return [
            ['userId', 'user_id'],
            ['isActiveFlag', 'is_active_flag'],
            ['HTMLParser', 'h_t_m_l_parser'], // Стандартное поведение простейшего конвертера
            ['already_snake', 'already_snake']
        ];
    }

    public function snakeToCamelProvider(): array
    {
        return [
            ['user_id', 'userId'],
            ['IS_ACTIVE_FLAG', 'isActiveFlag'],
            ['alreadyCamel', 'alreadycamel'] // Так как внутри идет strtolower в начале
        ];
    }
}