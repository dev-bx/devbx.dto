<?php

namespace DevBX\DTO\Tests;

use PHPUnit\Framework\TestCase;
use DevBX\DTO\BaseDTO;
use DevBX\DTO\Attributes\Behavior\SkipNull;

/**
 * Вспомогательный DTO для тестирования поведения SkipNull
 */
class SkipNullTestDTO extends BaseDTO
{
    #[SkipNull]
    public ?string $skippedWhenNull = null;

    #[SkipNull]
    public ?int $numberWithSkipNull = null;

    public ?string $regularField = null;
}

class SkipNullTest extends TestCase
{
    public function testSkipNullExcludesOnlyNullValues(): void
    {
        $dto = new SkipNullTestDTO();

        // Явно задаем null для проверки пропуска
        $dto->skippedWhenNull = null;

        // Обычное поле тоже null, но оно должно остаться
        $dto->regularField = null;

        $array = $dto->toArray();

        // 1. Поле с #[SkipNull] и значением null должно быть полностью удалено из массива
        $this->assertArrayNotHasKey('skippedWhenNull', $array);

        // 2. Обычное поле с null должно остаться в массиве
        $this->assertArrayHasKey('regularField', $array);
        $this->assertNull($array['regularField']);
    }

    public function testSkipNullIncludesNonNullValues(): void
    {
        $dto = new SkipNullTestDTO();

        // Задаем реальное значение
        $dto->skippedWhenNull = 'hello';

        // Задаем ложноподобное значение (0). Оно НЕ должно пропускаться!
        $dto->numberWithSkipNull = 0;

        $array = $dto->toArray();

        // 1. Поле с #[SkipNull], имеющее строку, должно быть в массиве
        $this->assertArrayHasKey('skippedWhenNull', $array);
        $this->assertEquals('hello', $array['skippedWhenNull']);

        // 2. Поле с #[SkipNull], имеющее значение 0, должно быть в массиве
        $this->assertArrayHasKey('numberWithSkipNull', $array);
        $this->assertEquals(0, $array['numberWithSkipNull']);
    }
}
