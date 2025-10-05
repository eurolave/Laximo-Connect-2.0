try {
    if ($path === '/vin') {
        $vin    = q('vin', '');
        $locale = q('locale', 'ru_RU') ?? 'ru_RU';
        if ($vin === '') {
            fail('vin required', 400);
        }
        $data = $client->findVehicleByVin($vin, $locale);
        ok(['data' => $data, 'vin' => $vin, 'locale' => $locale]);

    } elseif ($path === '/applicable') {
        $catalog = q('catalog', '');
        $oem     = q('oem', '');
        $locale  = q('locale', 'ru_RU') ?? 'ru_RU';

        if ($catalog === '' || $oem === '') {
            fail('catalog and oem are required', 400);
        }

        $data = $client->findApplicableVehicles($catalog, $oem, $locale);
        ok([
            'catalog' => $catalog,
            'oem'     => $oem,
            'locale'  => $locale,
            'data'    => $data,
        ]);

    } elseif ($path === '/categories') {
        $catalog   = q('catalog', '');
        $vehicleId = q('vehicleId', '0') ?? '0';
        $ssd       = q('ssd', '');
        $all       = q('all'); // ?all=1 -> полный список

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        }

        $data = ($all === '1')
            ? $client->listCategoriesAll($catalog, $vehicleId, $ssd)
            : $client->listCategories($catalog, $vehicleId, $ssd);

        ok([
            'catalog'   => $catalog,
            'vehicleId' => $vehicleId,
            'data'      => $data,
        ]);

    } elseif ($path === '/units') {
        // СПИСОК УЗЛОВ ВНУТРИ КАТЕГОРИИ
        $catalog    = q('catalog', '');
        $vehicleId  = q('vehicleId', '0') ?? '0';
        $ssd        = q('ssd', '');
        $categoryId = q('categoryId', null);

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        }
        if ($categoryId === null || $categoryId === '') {
            fail('categoryId is required (string)', 400);
        }

        $data = $client->listUnits($catalog, $vehicleId, $ssd, (string)$categoryId);

        ok([
            'catalog'    => $catalog,
            'vehicleId'  => $vehicleId,
            'categoryId' => (string)$categoryId,
            'data'       => $data,
        ]);

    } elseif ($path === '/unit') {
        // СОСТАВ/ДЕТАЛИ КОНКРЕТНОГО УЗЛА ПО SSD УЗЛА
        $catalog   = q('catalog', '');
        $vehicleId = q('vehicleId', '0') ?? '0';
        $ssd       = q('ssd', '');
        $locale    = q('locale', 'ru_RU') ?? 'ru_RU';

        if ($catalog === '' || $ssd === '') {
            fail('catalog and ssd are required', 400);
        }

        $data = $client->getUnitBySsd($catalog, $vehicleId, $ssd, $locale);

        ok([
            'catalog'   => $catalog,
            'vehicleId' => $vehicleId,
            'ssd'       => $ssd,
            'locale'    => $locale,
            'data'      => $data,
        ]);

    } elseif ($path === '/oem') {
        $article = q('article', '');
        $brand   = q('brand'); // may be null/empty

        if ($article === '') {
            fail('article required', 400);
        }

        $data = $client->findOem($article, $brand ?: null);
        ok([
            'data'    => $data,
            'article' => $article,
            'brand'   => $brand,
        ]);

    } elseif ($path === '/diag') {
        $oem   = new \GuayaquilLib\ServiceOem($login, $pass);
        $cats  = $oem->listCatalogs();
        $count = is_array($cats) ? count($cats) : 0;

        error_log('diag: listCatalogs count=' . $count);

        ok([
            'service'        => 'laximo',
            'php'            => PHP_VERSION,
            'soap'           => extension_loaded('soap'),
            'login_set'      => (bool) $login,
            'catalogs_count' => $count,
            'catalogs'       => $cats,
        ]);

    } else {
        ok(['service' => 'laximo']);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 400);
}
