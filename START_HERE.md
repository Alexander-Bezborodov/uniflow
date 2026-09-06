# Запуск UniFlow

Основная версия теперь на PHP 7.4+ и вебхуке.

1. Скопируйте `.env.example` в `.env`; задайте токен, HTTPS-адрес, секрет вебхука и общее время `TIMEZONE`.
2. Остановите старый Python-бот.
3. Выполните `php bin/console.php migrate` от пользователя PHP-FPM.
4. Настройте HTTPS с корнем сайта `public/`.
5. Запустите `php bin/console.php worker` как постоянный сервис.
6. Выполните `php bin/console.php webhook:set` и проверьте `/start`.

GigaChat пока выключен. После получения Authorization key заполните `GIGACHAT_AUTH_KEY`, включите `GIGACHAT_ENABLED=true` и перезапустите обработчик.

Подробности миграции, настройки сервиса и проверок: [README.md](README.md).
