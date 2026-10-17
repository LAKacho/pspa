# Анализ YouTube-каналов на Elasticsearch

Хранилище: Elasticsearch 8 (контейнер `pspa-elasticsearch`), PHP-клиент `elasticsearch/elasticsearch` (Composer).

## Структура хранения (пункт 1.1)

Два индекса. Связь «канал → видео» идёт через поле `channel_id`. Видео не вкладываются в документ канала: у канала тысячи видео, документ разрастался бы, а лайки видео меняются независимо от канала.

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

- Индексов Elasticsearch два: `channels` и `videos`. По одному шарду и без реплик (`number_of_shards: 1`, `number_of_replicas: 0`): данных мало, кластер из одного узла.
- Для агрегаций (сумма лайков, топ каналов) критичны типы полей. `channel_id` имеет тип `keyword`, а счётчики `long`. У них по умолчанию включены `doc_values` (колоночное хранилище), по которым считаются `terms` и `sum`. Если бы `channel_id` был `text`, агрегация по нему не работала бы.
- `title` хранится дважды: как `text` (поиск по словам) и как `keyword` в подполе `raw` (сортировка и точное совпадение).
- Отдельного индекса «по лайкам» не нужно: сумма и отношение вычисляются агрегациями на лету.

## Слои кода

| Слой | Файлы | Что делает |
|---|---|---|
| Хранилище | [Storage/ClientFactory.php](../src/Youtube/Storage/ClientFactory.php) | создаёт клиента Elasticsearch, адрес из `ELASTICSEARCH_URL` |
| Модели (1.2) | [Model/Channel.php](../src/Youtube/Model/Channel.php), [Model/Video.php](../src/Youtube/Model/Video.php) | данные канала и видео, преобразование в документ и обратно |
| Репозитории (1.2) | [Repository/ChannelRepository.php](../src/Youtube/Repository/ChannelRepository.php), [Repository/VideoRepository.php](../src/Youtube/Repository/VideoRepository.php) | добавление, поиск, удаление и агрегации; только они знают запросы к Elasticsearch |
| Статистика (1.3) | [Stats/ChannelStats.php](../src/Youtube/Stats/ChannelStats.php) | `totalLikesDislikes()` и `topByLikeRatio()` |
| Паук (1.4*) | [Spider/Spider.php](../src/Youtube/Spider/Spider.php), [Spider/YoutubeApi.php](../src/Youtube/Spider/YoutubeApi.php) | обход YouTube и сохранение через репозитории |

Класс статистики сам к Elasticsearch не обращается, он вызывает репозитории. Удаление канала (`ChannelRepository::delete`) по умолчанию удаляет и его видео.

### Как считается статистика

- **Сумма лайков и дизлайков канала:** запрос к `videos` с фильтром `term: channel_id` и двумя агрегациями `sum` по `likes` и `dislikes`.
- **Топ N каналов:** агрегация `terms` по `channel_id`, внутри `sum(likes)`, `sum(dislikes)`, `bucket_script` для отношения и `bucket_sort` (по убыванию отношения, затем по лайкам) с `size: N`. Вся работа выполняется на стороне Elasticsearch.
- **Отношение** = сумма лайков / сумма дизлайков. Если дизлайков нет, делитель принимается равным 1, иначе было бы деление на ноль. У канала без дизлайков отношение равно числу лайков.
- Ограничение: в топе учитываются до 10 000 каналов (`MAX_CHANNELS` в `VideoRepository`).

## Запуск

```powershell
docker compose -f docker-compose.yml up -d
docker run --rm -v "${PWD}\app:/app" -w /app composer:2 composer install
docker exec pspa-php-1 php bin/seed.php        # тестовые каналы и видео, вывод статистики
```

Индексы создаются из JSON командами (один раз):

```powershell
curl.exe -X PUT http://localhost:9200/channels -H "Content-Type: application/json" --data-binary "@app/es/channels.json"
curl.exe -X PUT http://localhost:9200/videos -H "Content-Type: application/json" --data-binary "@app/es/videos.json"
```

Elasticsearch доступен и с хоста: http://localhost:9200, например `http://localhost:9200/videos/_search`.

Пример вывода `bin/seed.php`:

```
Загружено каналов: 6, видео: 48

UC_tech: видео 8, лайков 7308, дизлайков 276

Топ 3 по отношению лайков к дизлайкам:
1. Тихий канал (UC_nodis): лайков 1068, дизлайков 0, отношение 1068.00
2. Tech Review (UC_tech): лайков 7308, дизлайков 276, отношение 26.48
3. Music Planet (UC_music): лайков 12108, дизлайков 836, отношение 14.48
```

## Паук (пункт 1.4*)

Паук берёт каналы и видео из YouTube Data API v3, сохраняет их через те же репозитории и при повторном запуске обновляет документы, а не дублирует (id документа равен id на YouTube). Видео берутся из плейлиста загрузок канала (дешевле по квоте, чем поиск), статистика запрашивается пачками по 50.

Ключ API хранится в файле `.env` в корне проекта (`YOUTUBE_API_KEY=...`, образец в `.env.example`). Файл `.env` в git не попадает.

```powershell
# конкретные каналы, по 20 последних видео
docker exec pspa-php-1 php bin/spider.php channel UCxxxx,UCyyyy 20
# поиск каналов по тексту: 5 каналов, по 20 видео (поиск стоит 100 единиц квоты)
docker exec pspa-php-1 php bin/spider.php search "кулинария" 5 20
```

YouTube с 2021 года не отдаёт дизлайки в своём API, поэтому поле `dislikes` паук берёт из сервиса Return YouTube Dislike (`returnyoutubedislikeapi.com`). Это оценка сервиса, а не данные YouTube; для новых видео и при недоступности сервиса значение может быть 0.

Проверено вживую: обход канала по id (10 видео) и поиск по тексту (2 канала, по 3 видео) сохранили данные в Elasticsearch, статистика по ним считается.
