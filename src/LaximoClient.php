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

    /** ───────────── Категории / Узлы ───────────── */

    /** Список категорий: listCategories(catalog, vehicleId, ssd) */
    public function listCategories(string $catalog, string $vehicleId, string $ssd): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd)
        ]);
        return $this->normalize($res);
    }

    /** Полный список категорий (иерархия): четвёртым аргументом передаём -1 */
    public function listCategoriesAll(string $catalog, string $vehicleId, string $ssd): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listCategories($catalog, $vehicleId, $ssd, -1)
        ]);
        return $this->normalize($res);
    }

   /** Узлы: listUnits(catalog, vehicleId, ssd, categoryId) */
public function listUnits(string $catalog, string $vehicleId, string $ssd, string $categoryId): array
{
    // ВАЖНО: передаём categoryId как строку — библиотека этого требует
    $res = $this->oem->queryButch([
        OemCmd::listUnits($catalog, $vehicleId, $ssd, (string)$categoryId),
    ]);
    return $this->normalize($res);
}
    /** Универсальная нормализация в «чистый» массив для JSON */
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

    /** Чистим служебные префиксы приватных свойств: "\0*\0prop" → "prop" */
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
