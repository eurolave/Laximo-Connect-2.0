<?php
declare(strict_types=1);

namespace App;

use GuayaquilLib\ServiceOem;
use GuayaquilLib\ServiceAm;

final class LaximoClient
{
    private ServiceOem $oem;
    private ServiceAm  $am;

    public function __construct(string $login, string $password)
    {
        $this->oem = new ServiceOem($login, $password);
        $this->am  = new ServiceAm($login, $password);

        // Жёстко задаём endpoint (если нужен): по умолчанию ws.laximo.ru
        $host = getenv('LAXIMO_HOST') ?: 'https://ws.laximo.ru';
        $this->forceHost($host);
    }

    /** VIN → данные автомобиля (OEM) */
    public function findByVin(string $vin): array
    {
        error_log("[LAXIMO] findByVin({$vin})");
        $res = $this->oem->findVehicleByVin($vin);
        return is_array($res) ? $res : [];
    }

    /** VIN → то же, что и findByVin (синоним, если удобнее такое имя) */
    public function findVehicleByVin(string $vin): array
    {
        error_log("[LAXIMO] findVehicleByVin({$vin})");
        $res = $this->oem->findVehicleByVin($vin);
        return is_array($res) ? $res : [];
    }

    /** Поиск запчастей по артикулу (Aftermarket) */
    public function findOem(string $article, ?string $brand = null): array
    {
        error_log("[LAXIMO] findOem(article={$article}, brand=" . ($brand ?? '') . ")");
        $res = $this->am->findOem($article, $brand ?? '');
        return is_array($res) ? $res : [];
    }

    /** Список доступных OEM-каталогов */
    public function listCatalogs(): array
    {
        error_log('[LAXIMO] listCatalogs()');
        $res = $this->oem->listCatalogs();
        return is_array($res) ? $res : [];
    }

    /**
     * Насильно направляем SDK на нужный endpoint (ws.laximo.ru).
     * Пытаемся сначала через «публичные» сеттеры, затем через приватные поля и SoapClient->__setLocation().
     */
    private function forceHost(string $host): void
    {
        try {
            // Попробуем с OEM
            $this->rewriteEndpoint($this->oem, $host);
            // И с AM
            $this->rewriteEndpoint($this->am,  $host);
        } catch (\Throwable $e) {
            error_log('[LAXIMO][forceHost] ' . $e->getMessage());
        }
    }

    /** Вспомогательно: перенастройка endpoint внутри объекта библиотеки */
    private function rewriteEndpoint(object $svc, string $host): void
    {
        $ref = new \ReflectionObject($svc);

        // 1) Если есть явные сеттеры — используем их
        foreach (['setHost', 'setEndpoint', 'setUrl'] as $m) {
            if ($ref->hasMethod($m)) {
                $ref->getMethod($m)->invoke($svc, $host);
            }
        }

        // 2) Ищем внутренний wrapper/soapClient
        $wrapper = null;
        foreach (['soap', 'client', 'soapClient', 'wrapper'] as $propName) {
            if ($ref->hasProperty($propName)) {
                $p = $ref->getProperty($propName);
                $p->setAccessible(true);
                $wrapper = $p->getValue($svc);
                if ($wrapper) break;
            }
        }

        if ($wrapper) {
            $wRef = new \ReflectionObject($wrapper);

            // 2a) Сеттеры в обёртке
            foreach (['setHost', 'setEndpoint', 'setUrl'] as $m) {
                if ($wRef->hasMethod($m)) {
                    $wRef->getMethod($m)->invoke($wrapper, $host);
                }
            }
            // 2b) Популярные поля для адреса
            foreach (['host', 'url', 'endpoint', 'baseUrl'] as $pn) {
                if ($wRef->hasProperty($pn)) {
                    $pp = $wRef->getProperty($pn);
                    $pp->setAccessible(true);
                    $pp->setValue($wrapper, $host);
                }
            }
            // 2c) SoapClient->__setLocation()
            foreach (['soap', 'client', 'soapClient'] as $pn) {
                if ($wRef->hasProperty($pn)) {
                    $pp = $wRef->getProperty($pn);
                    $pp->setAccessible(true);
                    $sc = $pp->getValue($wrapper);
                    if ($sc instanceof \SoapClient) {
                        try { @$sc->__setLocation($host); } catch (\Throwable) {}
                    }
                }
            }
        }
    }
}
