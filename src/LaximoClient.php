<?php
declare(strict_types=1);

namespace App;

use GuayaquilLib\Oem as OemCmd;
use GuayaquilLib\ServiceOem;
use GuayaquilLib\ServiceAm;

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

        // НИЧЕГО не форсим: пусть SDK сам выберет правильный endpoint для учётки.
        // Если когда-то понадобится — можно вернуть forceHost(), но это ломало каталоги у тебя.
    }

    /** Список доступных OEM-каталогов (через batch, чтобы вернуть «как есть») */
    public function listCatalogs(string $locale = 'ru_RU'): array
    {
        $this->log("listCatalogs(locale={$locale})");
        return $this->oem->queryButch([ OemCmd::listCatalogs($locale) ]);
    }

    /** VIN → данные об авто (OEM). По умолчанию ru_RU, можно указать свою локаль */
    public function findByVin(string $vin, string $locale = 'ru_RU'): array
    {
        $this->log("findByVin(vin={$vin}, locale={$locale})");
        // Через batch используем точную команду из SDK:
        return $this->oem->queryButch([ OemCmd::findVehicleByVin($vin, $locale) ]);
    }

    /** Синоним */
    public function findVehicleByVin(string $vin, string $locale = 'ru_RU'): array
    {
        return $this->findByVin($vin, $locale);
    }

    /** Применимость детали к авто по OEM + каталогу */
    public function findApplicableVehicles(string $catalog, string $oem, string $locale = 'ru_RU'): array
    {
        $this->log("findApplicableVehicles(catalog={$catalog}, oem={$oem}, locale={$locale})");
        return $this->oem->queryButch([ OemCmd::findVehicleByOem($catalog, $oem, $locale) ]);
    }

    /** Поиск запчастей по артикулу (Aftermarket / DOC). brand опционален */
    public function findOem(string $article, ?string $brand = null): array
    {
        $this->log("findOem(article={$article}, brand=" . ($brand ?? '') . ")");
        $res = $this->am->findOem($article, $brand ?? '');
        // SDK может вернуть массив объектов — просто отдаём «как есть»
        return \is_array($res) ? $res : (array)$res;
    }

    // ───────────────────────── Диагностика / «сырые» батчи ─────────────────────

    /** Сырой batch VIN (удобно для отладки структуры ответа) */
    public function rawBatchFindByVin(string $vin, string $locale = 'ru_RU'): array
    {
        return $this->oem->queryButch([ OemCmd::findVehicleByVin($vin, $locale) ]);
    }

    /** Сырой batch ListCatalogs */
    public function rawBatchListCatalogs(string $locale = 'ru_RU'): array
    {
        return $this->oem->queryButch([ OemCmd::listCatalogs($locale) ]);
    }

    // ───────────────────────────────────────────────────────────────────────────

    private function log(string $msg): void
    {
        if ($this->debug) {
            \error_log('[LAXIMO] ' . $msg);
        }
    }
}
