# Выкладка agregatory.pro на reg.ru

Каждые 30 минут забирает код сайта из `iamsitnikov-prog/food-delivery-site-10`,
собирает его, подкладывает `.htaccess` из этого репозитория (файл `htaccess`)
и выкладывает на хостинг reg.ru по FTP. Запустить вручную: Actions → «Выкладка
agregatory.pro на reg.ru» → Run workflow.

- `htaccess` — правила Apache (страницы пререндера, 404, редиректы). Блок
  редиректа на https закомментирован: включить после выпуска SSL-сертификата.
- `check.sh` — проверка сайта после выкладки.
