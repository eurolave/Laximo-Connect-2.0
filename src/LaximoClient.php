<?php
declare(strict_types=1);

namespace App;

use GuayaquilLib\Oem as OemCmd;
use GuayaquilLib\ServiceOem;
use GuayaquilLib\ServiceAm;
use SimpleXMLElement;

final class LaximoClient
{
    private ServiceOem $oem;
    private ServiceAm  $am;
    private bool $debug;

    public function __construct(string $login, string $password)
    {
        $this->oem = new ServiceOem($login, $password);
        $this->am  = new ServiceAm($login, $password);
        $this->debug = (\getenv('LOG_LEVEL') === 'debug');
    }

    /** ─────────── Базовые вызовы ─────────── */

    public function listCatalogs(string $locale = 'ru_RU'): array
    {
        $res = $this->oem->queryButch([ OemCmd::listCatalogs($locale) ]);
        return $this->normalize($res);
    }

    public function findByVin(string $vin, string $locale = 'ru_RU'): array
    {
        $res = $this->oem->queryButch([ OemCmd::findVehicleByVin($vin, $locale) ]);
        return $this->normalize($res);
    }

    public function findVehicleByVin(string $vin, string $locale = 'ru_RU'): array
    {
        return $this->findByVin($vin, $locale);
    }

    public function findApplicableVehicles(string $catalog, string $oem, string $locale = 'ru_RU'): array
    {
        $res = $this->oem->queryButch([ OemCmd::findVehicleByOem($catalog, $oem, $locale) ]);
        return $this->normalize($res);
    }

    /** Aftermarket/DOC */
    public function findOem(string $article, ?string $brand = null): array
    {
        $res = $this->am->findOem($article, $brand ?? '');
        return $this->normalize($res);
    }

    /** ───────────── Категории / Узлы / Детали ───────────── */

    /**
     * Список корневых/текущих категорий (по SSD уровня).
     * Обычно SSD берём из ответа findByVin (root SSD).
     */
    public function listCategories(
        string $catalog,
        string $vehicleId,
        string $ssd,
        string $locale = 'ru_RU'
    ): array {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd, /*CategoryId*/ null, $locale)
        ]);
        return $this->normalize($res);
    }

    /**
     * Полный список категорий (иерархия) — CategoryId = -1.
     */
    public function listCategoriesAll(
        string $catalog,
        string $vehicleId,
        string $ssd,
        string $locale = 'ru_RU'
    ): array {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd, -1, $locale)
        ]);
        return $this->normalize($res);
    }

    /**
     * Узлы по выбранной категории (ListUnits).
     * ВАЖНО: $ssd — это SSD категории (НЕ SSD юнита).
     */
    public function listUnits(
        string $catalog,
        string $vehicleId,
        string $ssd,
        string|int $categoryId,
        string $locale = 'ru_RU'
    ): array {
        $res = $this->oem->queryButch([
            OemCmd::listUnits($catalog, $vehicleId, $ssd, (int)$categoryId, $locale)
        ]);
        return $this->normalize($res);
    }

    /**
     * Детали конкретного узла (ListDetailByUnit) по UnitId.
     * ВАЖНО: $contextSsd — это SSD текущего уровня (обычно SSD категории, с которым вызывали ListUnits).
     * НЕ передавайте сюда $unit->ssd — он часто «другой» и не подходит контексту.
     */
    public function listDetailByUnit(
        string $catalog,
        string $vehicleId,
        string $contextSsd,
        string|int $unitId,
        string $locale = 'ru_RU',
        bool $localized = true,
        bool $withLinks = true
    ): array {
        $res = $this->oem->queryButch([
            OemCmd::listDetailByUnit($catalog, $vehicleId, $contextSsd, (int)$unitId, $locale, $localized, $withLinks)
        ]);
        return $this->normalize($res);
    }

    /** ───────────── Нормализация ───────────── */

    /** Универсальная нормализация в «чистый» массив для JSON */
    private function normalize(mixed $v): mixed
    {
        // 1) SimpleXMLElement → массив
        if ($v instanceof SimpleXMLElement) {
            return json_decode(json_encode($v, JSON_UNESCAPED_UNICODE), true);
        }

        // 2) Массив → рекурсивно
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$this->cleanKey($k)] = $this->normalize($val);
            }
            return $out;
        }

        // 3) Объект → раскрываем свойства (в т.ч. приватные), чистим ключи, рекурсивно
        if (is_object($v)) {
            // Попробуем jsonSerialize(), если есть
            if ($v instanceof \JsonSerializable) {
                return $this->normalize($v->jsonSerialize());
            }
            // В противном случае раскрываем объект в массив
            $arr = (array) $v;
            $out = [];
            foreach ($arr as $k => $val) {
                $out[$this->cleanKey($k)] = $this->normalize($val);
            }
            return $out;
        }

        // 4) Скаляр/NULL
        return $v;
    }

    /** Чистим служебные префиксы приватных свойств: "\0*\0prop" → "prop" */
    private function cleanKey(string|int $k): string|int
    {
        if (!is_string($k)) return $k;
        // Убираем префиксы приватных/защищённых свойств: "\0*\0" или "\0Class\0"
        if (str_starts_with($k, "\0")) {
            $pos = strrpos($k, "\0");
            if ($pos !== false) {
                $k = substr($k, $pos + 1);
            }
        }
        return $k;
    }
}
