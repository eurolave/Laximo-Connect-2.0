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

    /* ... всё что у вас было ... */

    /** Узлы: listUnits(catalog, vehicleId, ssd, categoryId) */
    public function listUnits(string $catalog, string $vehicleId, string $ssd, string $categoryId): array
    {
        $res = $this->oem->queryButch([
            OemCmd::listUnits($catalog, $vehicleId, $ssd, (string)$categoryId),
        ]);
        return $this->normalize($res);
    }

    /**
     * ───────────── Детали узла по SSD ─────────────
     * Возвращаем объединённый ответ:
     *  - unitInfo: информация об узле/схеме
     *  - parts: массив позиций/деталей
     *
     * В разных версиях GuayaquilLib команды могут называться чуть по-другому:
     *  - getUnitInfo / getUnitBySsd / getUnit
     *  - listUnitParts / getUnitParts
     */
    public function getUnitBySsd(string $catalog, string $vehicleId, string $ssd, string $locale = 'ru_RU'): array
    {
        // Попробуем получить 2 ответа за один батч
        $batch = [
            // Информация об узле
            OemCmd::getUnitInfo($catalog, $vehicleId, $ssd, $locale), // синонимы: getUnitBySsd / getUnit
            // Состав/позиции
            OemCmd::listUnitParts($catalog, $vehicleId, $ssd, $locale), // синоним: getUnitParts
        ];

        $res = $this->oem->queryButch($batch);
        $norm = $this->normalize($res);

        // Нормализуем к предсказуемому виду
        $unitInfo = $norm['unitInfo'] ?? $norm['GetUnitInfo'] ?? $norm[0] ?? [];
        $parts    = $norm['unitParts'] ?? $norm['ListUnitParts'] ?? $norm[1] ?? [];

        // У некоторых обёрток данные лежат глубже — поправим «защитно»
        $partsArr =
            (is_array($parts) && isset($parts['parts'])) ? $parts['parts'] :
            ((is_array($parts) && isset($parts[0])) ? $parts : []);

        return [
            'unitInfo' => $unitInfo,
            'parts'    => $partsArr,
        ];
    }

    /* ... normalize(), cleanKey() — без изменений ... */
}
