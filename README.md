# php-laximo

Мини-сервис на PHP для проксирования запросов к **Laximo** через библиотеку [`laximo/guayaquillib`].
Экспонирует HTTP-эндпоинты, которые удобно дёргать из вашего Telegram-бота (Node.js или любой другой):

- `GET /vin?vin=WAU...` — поиск автомобиля по VIN (OEM/Laximo.CAT)
- `GET /oem?article=90471-PX4-000&brand=HONDA` — поиск запчастей по артикулу (Aftermarket/Laximo.DOC)

## Быстрый старт (локально)

```bash
cp .env.example .env
# заполните LAXIMO_LOGIN и LAXIMO_PASSWORD
composer install
php -S 0.0.0.0:8081 -t public
# сервис будет на http://localhost:8081
```

Примеры запросов:
```
curl "http://localhost:8081/vin?vin=WAUZZZ4M6JD010702"
curl "http://localhost:8081/oem?article=90471-PX4-000&brand=HONDA"
```

## Docker

```bash
docker build -t php-laximo .
docker run --rm -p 8081:8081 \
  -e LAXIMO_LOGIN=your_login -e LAXIMO_PASSWORD=your_password php-laximo
```

## Переменные окружения

- `LAXIMO_LOGIN` — логин Laximo
- `LAXIMO_PASSWORD` — пароль Laximo
- `APP_DEBUG` — `true/false` (необязательно)

## Структура
```
php-laximo/
├─ public/
│  └─ index.php         # HTTP-эндпоинты
├─ src/
│  └─ LaximoClient.php  # обёртка над guayaquillib
├─ composer.json
├─ .env.example
├─ .gitignore
└─ Dockerfile
```

## Лицензия
MIT
