<?php

// Предполагается стандартный автозагрузчик Composer, адаптируйте путь при необходимости
require_once __DIR__ . '/../../vendor/autoload.php';

use DevBX\DTO\BaseDTO;
use DevBX\DTO\BaseCollection;
use DevBX\DTO\Attributes\CollectionType;
use DevBX\DTO\Attributes\Cast;
use DevBX\DTO\Attributes\Mapping\MapFrom;
use DevBX\DTO\Attributes\Mapping\Computed;
// --- 1. Подготовка классов для высокой нагрузки на рефлексию ---

class BenchItemDTO extends BaseDTO
{
    public int $id;
    public string $name;
    public float $price;
}

class BenchItemCollection extends BaseCollection {}

class BenchOrderDTO extends BaseDTO
{
    #[MapFrom('order_id')]
    public int $id;

    public string $status;

    #[Cast(BenchItemDTO::class)]
    public BenchItemCollection $items;

    #[Computed]
    public function getTotal(): float
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->price;
        }
        return $total;
    }
}

// --- 2. Подготовка данных ---

$data = [
    'order_id' => 998877,
    'status' => 'processing',
    'items' => [
        ['id' => 1, 'name' => 'Product Alpha', 'price' => 10.50],
        ['id' => 2, 'name' => 'Product Beta', 'price' => 20.00],
        ['id' => 3, 'name' => 'Product Gamma', 'price' => 15.25],
        ['id' => 4, 'name' => 'Product Delta', 'price' => 8.99],
        ['id' => 5, 'name' => 'Product Epsilon', 'price' => 105.00],
    ]
];

// --- 3. Запуск бенчмарка ---

$iterations = 10000; // 10 тысяч полных циклов гидратации и сериализации

echo "Starting benchmark for {$iterations} iterations...\n";
echo "Testing: fromArray() + toArray() with nested collections and attributes.\n";
echo "--------------------------------------------------\n";

$startTime = microtime(true);
$startMemory = memory_get_usage();

for ($i = 0; $i < $iterations; $i++) {
    // 1. Парсинг массива, инстанцирование объектов, применение Cast и MapFrom
    $dto = BenchOrderDTO::fromArray($data);

    // 2. Сериализация обратно в массив, вызов Computed-методов
    $array = $dto->toArray(BaseDTO::FORMAT_SNAKE);
}

$endTime = microtime(true);
$endMemory = memory_get_peak_usage();

$timeTaken = round($endTime - $startTime, 4);
$memoryUsed = round(($endMemory - $startMemory) / 1024 / 1024, 2);

echo "Time taken: {$timeTaken} seconds\n";
echo "Peak memory usage: {$memoryUsed} MB\n";
echo "--------------------------------------------------\n";
echo "Save these results to compare after implementing Reflection Cache!\n";