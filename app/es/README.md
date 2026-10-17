# Анализ YouTube-каналов на Elasticsearch

Хранилище: Elasticsearch 8 (контейнер `pspa-elasticsearch`), PHP-клиент `elasticsearch/elasticsearch` (Composer).

## Структура хранения (пункт 1.1)

Два индекса. Связь «канал → видео» через поле `channel_id` (вложенные документы не используются: у канала тысячи видео, документ канала разрастался бы, а лайки видео меняются независимо).

Описания индексов в JSON: [channels.json](channels.json), [videos.json](videos.json).

### `channels`

| Поле | Тип | Назначение |
|---|---|---|
| `channel_id` | `keyword` | идентификатор канала (он же `_id` документа) |
| `title` | `text` + подполе `title.raw` (`keyword`) | поиск по словам и точная сортировка/группировка |
| `description` | `text` | полнотекстовый поиск |
| `country` | `keyword` | фильтр по стране |
| `subscribers` | `long` | число подписчиков |
| `created_at` | `date` | дата создания канала |

### `videos`

| Поле | Тип | Назначение |
|---|---|---|
| `video_id` | `keyword` | идентификатор видео (он же `_id` документа) |
| `channel_id` | `keyword` | канал-владелец, по нему идут фильтры и агрегации |
| `title` | `text` + подполе `title.raw` (`keyword`) | поиск и сортировка |
| `description` | `text` | полнотекстовый поиск |
| `tags` | `keyword` (массив) | точный фильтр по тегам |
| `views`, `likes`, `dislikes` | `long` | счётчики для сумм и отношения |
| `published_at` | `date` | дата публикации |

### Какие индексы нужны и почему

- Индексов Elasticsearch два: `channels` и `videos`. Один шард и ноль реплик (`number_of_shards: 1`, `number_of_replicas: 0`): данных мало, кластер из одного узла.
- Для агрегаций (сумма лайков, топ каналов) критичны типы полей: `channel_id` как `keyword` и счётчики как `long`. У них по умолчанию включены `doc_values` (колоночное хранилище), по которым считаются `terms` и `sum`. Если бы `channel_id` был `text`, агрегация по нему не работала бы.
- `title` хранится дважды: как `text` (для поиска по словам) и как `keyword` в подполе `raw` (для сортировки и точного совпадения).
- Отдельного индекса «по лайкам» не нужно: сумма и отношение вычисляются агрегациями на лету.

## Слой кода

| Слой | Файлы | Что делает |
|---|---|---|
| Хранилище | [Storage/ClientFactory.php](../src/Youtube/Storage/ClientFactory.php), [Storage/IndexManager.php](../src/Youtube/Storage/IndexManager.php) | создаёт клиента, создаёт/удаляет индексы по JSON |
| Модели (пункт 1.2) | [Model/Channel.php](../src/Youtube/Model/Channel.php), [Model/Video.php](../src/Youtube/Model/Video.php) | данные канала и видео, преобразование в документ и обратно |
| Репозитории (пункт 1.2) | [Repository/ChannelRepository.php](../src/Youtube/Repository/ChannelRepository.php), [Repository/VideoRepository.php](../src/Youtube/Repository/VideoRepository.php) | добавление, поиск, удаление; только они знают запросы к Elasticsearch |
| Статистика (пункт 1.3) | [Stats/ChannelStats.php](../src/Youtube/Stats/ChannelStats.php) | `totalLikesDislikes()` и `topByLikeRatio()` |

Класс статистики сам к Elasticsearch не обращается, он вызывает репозитории. Удаление канала (`ChannelRepository::delete`) по умолчанию удаляет и его видео.

### Как считается статистика

- **Сумма лайков и дизлайков канала:** запрос к `videos` с фильтром `term: channel_id` и двумя агрегациями `sum` по `likes` и `dislikes`.
- **Топ N каналов:** агрегация `terms` по `channel_id`, внутри `sum(likes)`, `sum(dislikes)`, `bucket_script` для отношения и `bucket_sort` (по убыванию отношения, затем по лайкам) с `size: N`. Вся работа выполняется на стороне Elasticsearch.
- **Отношение лайков к дизлайкам** = сумма лайков / сумма дизлайков. Если дизлайков нет, делитель принимается равным 1 (иначе деление на ноль). Поэтому у канала без дизлайков отношение равно числу лайков.
- Ограничение: в топе учитываются до 10 000 каналов (`MAX_CHANNELS` в `VideoRepository`).

## Веб-интерфейс

Страница: http://localhost/youtube.php ([public/youtube.php](../public/youtube.php)). Показывает состояние Elasticsearch и число документов, топ N каналов по отношению лайков к дизлайкам, таблицу всех каналов с суммами, видео выбранного канала. Есть формы добавления канала и видео, кнопки удаления канала (вместе с видео) и видео, кнопка заполнения тестовыми данными.

## Запуск

```powershell
docker compose -f docker-compose.yml up -d
docker run --rm -v "${PWD}\app:/app" -w /app composer:2 composer install
docker exec pspa-php-1 php bin/seed.php               # пересоздать индексы и заполнить тестовыми данными
docker exec pspa-php-1 php bin/stats.php 3 UC_tech    # топ-3 каналов и сумма лайков канала UC_tech
docker exec pspa-php-1 php bin/delete.php UC_news     # удалить канал вместе с видео
```

Elasticsearch доступен и с хоста: http://localhost:9200, например `http://localhost:9200/videos/_search`.

Пример вывода `bin/stats.php 6 UC_tech`:

```
Канал UC_tech: видео 8, лайков 7308, дизлайков 276

Топ 6 каналов по отношению лайков к дизлайкам:
1. Тихий канал (UC_nodis)  лайков  1068  дизлайков     0  отношение 1068.00  (дизлайков нет, делитель = 1)
2. Tech Review    (UC_tech)  лайков  7308  дизлайков   276  отношение 26.48
3. Music Planet   (UC_music)  лайков 12108  дизлайков   836  отношение 14.48
4. Кухня дома (UC_cook)  лайков  5708  дизлайков   596  отношение 9.58
5. Игровой мир (UC_games)  лайков  6508  дизлайков  3236  отношение 2.01
6. Новости дня (UC_news)  лайков  1708  дизлайков  4836  отношение 0.35
```

## Паук (пункт 1.4*)

Файлы: [Spider/Spider.php](../src/Youtube/Spider/Spider.php) (обход), [Spider/YoutubeApi.php](../src/Youtube/Spider/YoutubeApi.php) (клиент API), [bin/spider.php](../bin/spider.php) (запуск).

Паук берёт каналы и видео из YouTube Data API v3 (нужен ключ в переменной `YOUTUBE_API_KEY`), сохраняет их через те же репозитории и при повторном запуске обновляет документы, а не дублирует (id документа равен id на YouTube). Видео берутся из плейлиста загрузок канала (дешевле по квоте, чем поиск), статистика запрашивается пачками по 50.

```powershell
# конкретные каналы, по 20 последних видео
docker exec -e YOUTUBE_API_KEY=ваш_ключ pspa-php-1 php bin/spider.php channel UCxxxx,UCyyyy 20
# поиск каналов по тексту: 5 каналов, по 20 видео (поиск стоит 100 единиц квоты)
docker exec -e YOUTUBE_API_KEY=ваш_ключ pspa-php-1 php bin/spider.php search "кулинария" 5 20
```

YouTube с 2021 года не отдаёт дизлайки в своём API, поэтому поле `dislikes` паук берёт из сервиса Return YouTube Dislike (`returnyoutubedislikeapi.com`). Это оценка сервиса, а не данные YouTube; при недоступности сервиса дизлайки записываются как 0.

Проверка: без ключа паук проверяется заглушкой API командой `docker exec pspa-php-1 php bin/spider_selftest.php` (7 проверок, тестовые данные удаляются). Реальный обход YouTube с ключом не запускался, так как ключа не было: проверены только разбор ответов по документации API и сервис дизлайков (он ответил реальным числом для тестового видео).
