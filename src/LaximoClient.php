<?php
declare(strict_types=1);

namespace App;

use GuayaquilLib\Oem as OemCmd;
use GuayaquilLib\ServiceOem;
use GuayaquilLib\ServiceAm;
use JsonSerializable;
use SimpleXMLElement;
use Traversable;
use RuntimeException;

final class LaximoClient
{
    private ServiceOem $oem;
    private ServiceAm  $am;

    public function __construct(string $login, string $password)
    {
        $this->oem = new ServiceOem($login, $password);
        $this->am  = new ServiceAm($login, $password);
    }

    /**
     * Получить список каталогов.
     * @return array<mixed>
     */
    public function listCatalogs(string $locale = 'ru_RU'): array
    {
        $res = $this->oemBatch([OemCmd::listCatalogs($locale)]);
        return $this->normalize($res);
    }

    /**
     * Поиск авто по VIN (основной метод).
     * @return array<mixed>
     */
    public function findVehicleByVin(string $vin, string $locale = 'ru_RU'): array
    {
        $res = $this->oemBatch([OemCmd::findVehicleByVin($vin, $locale)]);
        return $this->normalize($res);
    }

    /**
     * @deprecated Используйте findVehicleByVin()
     * @return array<mixed>
     */
    public function findByVin(string $vin, string $locale = 'ru_RU'): array
    {
        return $this->findVehicleByVin($vin, $locale);
    }

    /**
     * Найти применимые авто по OEM-номеру.
     * @return array<mixed>
     */
    public function findApplicableVehicles(string $catalog, string $oemNumber, string $locale = 'ru_RU'): array
    {
        $res = $this->oemBatch([OemCmd::findVehicleByOem($catalog, $oemNumber, $locale)]);
        return $this->normalize($res);
    }

    /**
     * Aftermarket/DOC — поиск кроссов по артикулу.
     * @return array<mixed>
     */
    public function findOem(string $article, ?string $brand = null): array
    {
        $res = $this->am->findOem($article, $brand ?? '');
        return $this->normalize($res);
    }

    /**
     * Универсальная нормализация в «чистый» массив/скаляр для JSON.
     * @return mixed
     */
    private function normalize(mixed $value): mixed
    {
        // 1) SimpleXMLElement → массив (через json_encode для сохранения вложенности)
        if ($value instanceof SimpleXMLElement) {
            /** @var mixed $decoded */
            $decoded = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE), true);
            return $this->normalize($decoded);
        }

        // 2) Traversable → массив
        if ($value instanceof Traversable) {
            $tmp = [];
            foreach ($value as $k => $v) {
                $tmp[$this->cleanKey($k)] = $this->normalize($v);
            }
            return $tmp;
        }

        // 3) Массив → рекурсивно
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$this->cleanKey($k)] = $this->normalize($v);
            }
            return $out;
        }

        // 4) Объект → jsonSerialize() или раскрытие свойств
        if (is_object($value)) {
            if ($value instanceof JsonSerializable) {
                return $this->normalize($value->jsonSerialize());
            }
            // В явный массив со снятием приватных префиксов
            $out = [];
            foreach ((array)$value as $k => $v) {
                $out[$this->cleanKey($k)] = $this->normalize($v);
            }
            return $out;
        }

        // 5) Скаляр/NULL → как есть
        return $value;
    }

    /**
     * Чистим служебные префиксы приватных свойств: "\0*\0prop" или "\0Class\0prop" → "prop".
     * @param string|int $key
     * @return string|int
     */
    private function cleanKey(string|int $key): string|int
    {
        if (!is_string($key)) {
            return $key;
        }
        // удаляем префикс до последнего \0
        // пример: "\0*\0prop" или "\0Class\0prop" → "prop"
        return preg_replace('/^\x00(?:[^\x00]+)\x00/', '', $key) ?? $key;
    }

    /**
     * Единая точка вызова пакетных OEM-команд.
     * Оборачиваем, чтобы было проще менять реализацию (и не размножать "queryButch").
     * @param array<mixed> $commands
     * @return mixed
     */
    private function oemBatch(array $commands): mixed
    {
        try {
            // В библиотеке метод называется именно queryButch — оставлено как есть.
            return $this->oem->queryButch($commands);
        } catch (\Throwable $e) {
            throw new RuntimeException('OEM batch request failed: '.$e->getMessage(), 0, $e);
        }
    }
}
