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

    /** ───────────── Каталоги ───────────── */
    public function listCatalogs(string $locale = 'ru_RU'): array
    {
        $res = $this->oem->queryButch([ OemCmd::listCatalogs($locale) ]);
        return $this->normalize($res);
    }

    /** ───────────── VIN ───────────── */
    public function findByVin(string $vin, string $locale = 'ru_RU'): array
    {
        $res = $this->oem->queryButch([ OemCmd::findVehicleByVin($vin, $locale) ]);
        return $this->normalize($res);
    }

    /** Алиас, который вызывает findByVin — чтобы index.php мог звать его напрямую */
    public function findVehicleByVin(string $vin, string $locale = 'ru_RU'): array
    {
        return $this->findByVin($vin, $locale);
    }

    /** ───────────── Applicable по артикулу ───────────── */
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

    /** ───────────── Категории / Узлы ───────────── */

    /** Список категорий */
    public function listCategories(string $catalog, string $vehicleId, string $ssd): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd)
        ]);
        return $this->normalize($res);
    }

    /** Полный список категорий (иерархия) — -1 в 4-м параметре */
    public function listCategoriesAll(string $catalog, string $vehicleId, string $ssd): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd, -1)
        ]);
        return $this->normalize($res);
    }

    /** Узлы в категории */
    public function listUnits(string $catalog, string $vehicleId, string $ssd, string $categoryId): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listUnits($catalog, $vehicleId, $ssd, (string)$categoryId),
        ]);
        return $this->normalize($res);
    }

    /**
     * ───────────── Детали узла по SSD ─────────────
     * Возвращает:
     *  - unitInfo: инфо по узлу/схеме
     *  - parts: массив позиций/деталей
     *
     * Если в вашей версии GuayaquilLib названия отличаются, замените
     * getUnitInfo → getUnit / getUnitBySsd, listUnitParts → getUnitParts.
     */
    public function getUnitBySsd(string $catalog, string $vehicleId, string $ssd, string $locale = 'ru_RU'): array
    {
        $batch = [
            OemCmd::getUnitInfo($catalog, $vehicleId, $ssd, $locale),   // или getUnit / getUnitBySsd
            OemCmd::listUnitParts($catalog, $vehicleId, $ssd, $locale), // или getUnitParts
        ];

        $res  = $this->oem->queryButch($batch);
        $norm = $this->normalize($res);

        // Приводим к стабильным ключам
        $unitInfo = $norm['unitInfo']     ?? $norm['GetUnitInfo']     ?? ($norm[0] ?? []);
        $partsRaw = $norm['unitParts']    ?? $norm['ListUnitParts']   ?? ($norm[1] ?? []);

        // Плоский массив деталей
        $parts =
            (is_array($partsRaw) && isset($partsRaw['parts']) && is_array($partsRaw['parts'])) ? $partsRaw['parts'] :
            ((is_array($partsRaw) && isset($partsRaw[0])) ? $partsRaw : []);

        return [
            'unitInfo' => $unitInfo,
            'parts'    => $parts,
        ];
    }

    /** ───────────── Нормализация ───────────── */

    private function normalize(mixed $v): mixed
    {
        if ($v instanceof SimpleXMLElement) {
            return json_decode(json_encode($v, JSON_UNESCAPED_UNICODE), true);
        }

        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $out[$this->cleanKey($k)] = $this->normalize($val);
            }
            return $out;
        }

        if (is_object($v)) {
            if ($v instanceof \JsonSerializable) {
                return $this->normalize($v->jsonSerialize());
            }
            $arr = (array) $v;
            $out = [];
            foreach ($arr as $k => $val) {
                $out[$this->cleanKey($k)] = $this->normalize($val);
            }
            return $out;
        }

        return $v;
    }

    private function cleanKey(string|int $k): string|int
    {
        if (!is_string($k)) return $k;
        if (str_starts_with($k, "\0")) {
            $pos = strrpos($k, "\0");
            if ($pos !== false) {
                $k = substr($k, $pos + 1);
            }
        }
        return $k;
    }
}
