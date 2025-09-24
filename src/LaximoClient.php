<?php
namespace App;

use Laximo\Guayaquil\ServiceOem;
use Laximo\Guayaquil\ServiceAm;

class LaximoClient {
    private ServiceOem $oem;
    private ServiceAm $am;

    public function __construct(string $login, string $password) {
        $this->oem = new ServiceOem($login, $password);
        $this->am  = new ServiceAm($login, $password);
    }

    /** Поиск авто по VIN */
    public function findByVin(string $vin): array {
        $res = $this->oem->findVehicleByVin($vin);
        return is_array($res) ? $res : [];
    }

    /** Поиск по артикулу (Aftermarket / DOC) */
    public function findOem(string $article, ?string $brand=null): array {
        $res = $this->am->findOem($article, $brand ?? '');
        return is_array($res) ? $res : [];
    }
}
