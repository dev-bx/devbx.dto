<?php

namespace Local\Lib\DTO\Utils;

/**
 * Внутренняя утилита для работы со строками.
 * Заменяет зависимость от Bitrix\Main\Text\StringHelper.
 */
class StringHelper
{
    /**
     * Преобразует строку из camelCase в snake_case.
     * Пример: userId -> user_id
     */
    public static function camel2snake(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    /**
     * Преобразует строку из snake_case или UPPER_SNAKE_CASE в camelCase.
     * Пример: USER_ID -> userId, user_id -> userId
     */
    public static function snake2camel(string $string): string
    {
        $string = strtolower($string);
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        $string = str_replace(' ', '', $string);

        return lcfirst($string);
    }
}