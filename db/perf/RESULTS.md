# Оптимизация производительности БД кинотеатра (MySQL 8.0)

Файлы:

| Файл | Назначение |
|---|---|
| [../init/01_schema.sql](../init/01_schema.sql) | скрипт создания БД |
| [generate.sql](generate.sql) | заполнение тестовыми данными (`small` ≈ 10 тыс. строк, `big` ≈ 10 млн) |
| [queries.sql](queries.sql) | 6 основных запросов (+ переписанный вариант 4b) |
| [explain.sql](explain.sql) | снятие планов (`EXPLAIN ANALYZE`) |
| [optimize.sql](optimize.sql) | добавленные индексы |
| [admin.sql](admin.sql), [admin_report.txt](admin_report.txt) | размеры объектов и статистика использования индексов |
| [plans/10k/plans.txt](plans/10k/plans.txt) | планы на ~10 тыс. строк |
| [plans/10m/plans.txt](plans/10m/plans.txt) | планы на ~10 млн строк, без оптимизаций |
| [plans/10m_opt/plans.txt](plans/10m_opt/plans.txt), [q4_rewritten.txt](plans/10m_opt/q4_rewritten.txt) | планы на ~10 млн строк после оптимизаций |

## Объём данных

| Масштаб | movie | screening | customer | ticket | Всего строк |
|---|---|---|---|---|---|
| small | 50 | 100 | 1 000 | 8 069 | 10 591 |
| big | 500 | 75 000 | 1 800 000 | 8 166 942 | ≈ 10,05 млн |

Цифра «всего строк» для `big` посчитана по составу таблиц; итоговый `SELECT` в конце [generate.sql](generate.sql) выводит точное значение. В `big` за последнюю неделю куплено ≈ 180 тыс. билетов из 8,17 млн (≈ 2 %), на сегодня идёт 180 сеансов из 75 тыс.

## Результаты по запросам

Время взято из `EXPLAIN ANALYZE` (actual time верхнего узла). Тип: П — простой (одна таблица), С — сложный (JOIN, агрегаты).

| № | Запрос | Тип | План 10k | План 10M | План 10M после оптимизации |
|---|---|---|---|---|---|
| 1 | Сеансы на сегодня | П | `Table scan` по 100 строк, 0,13 мс | `Table scan` по 75 000 строк ради 180, **34,4 мс** | `Covering index range scan` по `idx_screening_starts_at`, **0,10 мс** |
| 2 | Билетов продано за неделю | П | `Table scan`, 8 069 строк, 2,7 мс | `Table scan` по 8,17 млн строк ради 180 тыс., **4091 мс** | `Covering index range scan` по `idx_ticket_purchased`, **67 мс** |
| 3 | Афиша на сегодня | С | `Table scan` + `Single-row lookup` по `movie`, 0,56 мс | `Table scan` по 75 000 строк, **41,2 мс** | `Covering index range scan` + lookup по `movie`, **1,16 мс** |
| 4 | Топ-3 фильма по выручке за неделю | С | `Table scan` по `ticket` + 2 lookup по PRIMARY, 35,2 мс | `Table scan` по 8,17 млн строк, затем 180 тыс. lookup, **5677 мс** | индекс `idx_ticket_purchased`: **1671 мс**; индекс + переписанный запрос (4b): **273 мс** |
| 5 | Схема зала на сеанс | С | `seat_index_2` + `ticket_index_9`, 1,5 мс | то же, 0,93 мс | то же, 0,87 мс (оптимизация не требуется) |
| 6 | Мин. и макс. цена на сеанс | П | `Index lookup` по PRIMARY, 0,025 мс | то же, 0,028 мс | то же, 0,026 мс (оптимизация не требуется) |

Ускорение на 10M: Q1 в ~350 раз, Q2 в ~60 раз, Q3 в ~35 раз, Q4 в ~21 раз (в ~3,4 раза только от индекса и ещё в ~6 раз от переписывания). Q5 и Q6 были быстрыми изначально.

На 10k данных все планы выглядят «нормально» именно из-за малого размера: `Table scan` по 100 или 8 тыс. строк занимает миллисекунды. Проблема проявляется только на 10M, где объём таблицы вырос в ~1000 раз, а выбирается по-прежнему небольшая доля строк.

## Перечень оптимизаций

| Оптимизация | Что делает | Для каких запросов | Результат |
|---|---|---|---|
| `INDEX screening (starts_at, movie_id, hall_id)` | Диапазон по `starts_at` читается по индексу, а не по всей таблице. Индекс **покрывающий**: в нём есть `movie_id` и `hall_id`, а `id` (PRIMARY) добавляется автоматически, поэтому к самой таблице обращаться не нужно. `ORDER BY starts_at` тоже выполняется по индексу, без сортировки | 1, 3 | 34 мс → 0,10 мс, 41 мс → 1,16 мс |
| `INDEX ticket (purchased_at, screening_id, price_paid)` | Диапазон по `purchased_at` читается по индексу. Индекс покрывающий: `screening_id` нужен для JOIN, `price_paid` для `SUM`, `id` для `COUNT` входит автоматически. Обращения к таблице `ticket` нет | 2, 4 | 4091 мс → 67 мс, 5677 мс → 1671 мс |
| Переписывание запроса 4 (вариант 4b) | Билеты сначала группируются по `screening_id` (≈ 1,8 тыс. групп из 180 тыс. строк), и только потом присоединяются `screening` и `movie`. Меньше обращений по PRIMARY и меньше строк в агрегации. Результат идентичен | 4 | 1671 мс → 273 мс |
| Условия по датам диапазоном (`>=` и `<`) без `DATE(колонка)` | Условие, в котором на колонку наложена функция, не использует индекс. Все запросы изначально написаны без функций над колонками | 1, 2, 3, 4 | необходимое условие для работы индексов |
| `ANALYZE TABLE` после загрузки | Обновляет статистику, по которой оптимизатор выбирает план | все | входит в [generate.sql](generate.sql) и [optimize.sql](optimize.sql) |

**Цена оптимизации.** Индексы занимают место и замедляют вставку: `idx_ticket_purchased` весит 212 МБ (при размере таблицы `ticket` 1511 МБ с индексами), `idx_screening_starts_at` занимает несколько МБ. Для кинотеатра это приемлемо: билеты чаще читаются, чем покупаются, и каждая покупка добавляет всего одну запись в индекс.

**Что не делали и почему.**
- Q5 использует `seat_index_2 (hall_id, row_no, seat_no)` и `ticket_index_9 (screening_id, seat_id)`, Q6 использует PRIMARY `screening_price`. Время менее 1 мс, добавлять нечего.
- Партиционирование `ticket` по `purchased_at` дало бы выигрыш на архивации старых данных, но для этих запросов индекса достаточно. Партиционирование осложняет внешние ключи: в MySQL таблицы с FK партиционировать нельзя.
- Сводная таблица выручки по сеансам (денормализация) ускорила бы Q4 до миллисекунд, но требует поддержки согласованности при каждой покупке. Для учебной БД и текущих 273 мс это избыточно.
- Параметры сервера: `innodb_buffer_pool_size` увеличивает долю данных в памяти, что ускоряет повторные запросы и загрузку. При 1,5 ГБ таблицы `ticket` значение по умолчанию (128 МБ) мало.

## 15 самых больших объектов БД

Размер таблиц: `data_length + index_length`, размер индексов: из `mysql.innodb_index_stats`. Источник: [admin_report.txt](admin_report.txt).

| № | Тип | Объект | Размер, МБ |
|---|---|---|---|
| 1 | таблица (данные + индексы) | ticket | 1511,06 |
| 2 | индекс | ticket.PRIMARY | 408,88 |
| 3 | индекс | ticket.ticket_index_10 | 305,00 |
| 4 | индекс | ticket.ticket_index_9 | 288,86 |
| 5 | таблица (данные + индексы) | customer | 264,59 |
| 6 | индекс | ticket.idx_ticket_purchased | 211,84 |
| 7 | индекс | customer.PRIMARY | 158,67 |
| 8 | индекс | ticket.ticket_index_11 | 157,77 |
| 9 | индекс | ticket.screening_id | 138,72 |
| 10 | индекс | customer.email | 105,92 |
| 11 | таблица (данные + индексы) | screening_price | 22,03 |
| 12 | индекс | screening_price.PRIMARY | 16,52 |
| 13 | таблица (данные + индексы) | screening | 12,58 |
| 14 | индекс | screening_price.screening_price_index_8 | 5,52 |
| 15 | индекс | screening.PRIMARY | 3,52 |

PRIMARY InnoDB-таблицы хранит сами строки (кластерный индекс), поэтому `ticket.PRIMARY` и `customer.PRIMARY` оказываются одновременно в списке «индексов» и входят в размер таблицы.

## Использование индексов

Счётчики из `performance_schema.table_io_waits_summary_by_index_usage` накапливаются с момента запуска сервера и включают загрузку данных и пять прогонов [queries.sql](queries.sql).

**Пять самых часто используемых:**

| Таблица | Индекс | Чтений |
|---|---|---|
| seat | seat_index_2 | 38 612 211 |
| customer | email | 3 606 000 |
| ticket | idx_ticket_purchased | 1 803 467 |
| movie | PRIMARY | 901 838 |
| screening | PRIMARY | 900 346 |

`seat_index_2` лидирует из-за запроса 5, который перебирает все 200 мест зала. Чтения `customer.email` (ровно 2 на каждую из 1,8 млн вставленных строк) не связаны с шестью запросами: вероятно, это проверка уникальности при загрузке данных.

**Пять самых редко используемых (0 чтений):**

| Таблица | Индекс | Чтений |
|---|---|---|
| customer | PRIMARY | 0 |
| movie_genre | PRIMARY | 0 |
| genre | PRIMARY | 0 |
| genre | name | 0 |
| hall | PRIMARY | 0 |

Все они принадлежат справочным таблицам, которые наши шесть запросов не читают. При нулевых чтениях сортировка внутри группы произвольна: таких индексов больше пяти, в том числе крупные `ticket_index_10`, `ticket_index_11` и `ticket.screening_id` (около 600 МБ вместе). Это индексы внешних ключей: запросы их не используют, но они нужны для проверки целостности при вставке и удалении, поэтому удалять их без пересмотра схемы не стоит.

## Приложение: полные планы выполнения

### 10 тыс. строк

```
sid = 61
=== Q1: сеансы на сегодня ===
-> Sort: screening.starts_at  (cost=1.36 rows=100) (actual time=0.131..0.134 rows=30 loops=1)
    -> Filter: ((screening.starts_at >= <cache>(curdate())) and (screening.starts_at < <cache>((curdate() + interval 1 day))))  (cost=1.36 rows=100) (actual time=0.0787..0.103 rows=30 loops=1)
        -> Table scan on screening  (cost=1.36 rows=100) (actual time=0.0506..0.0753 rows=100 loops=1)

=== Q2: билеты за неделю ===
-> Aggregate: count(0)  (cost=554 rows=1) (actual time=2.69..2.69 rows=1 loops=1)
    -> Filter: (ticket.purchased_at >= <cache>((now() - interval 7 day)))  (cost=281 rows=2737) (actual time=0.126..2.39 rows=8069 loops=1)
        -> Table scan on ticket  (cost=281 rows=8212) (actual time=0.121..1.71 rows=8069 loops=1)

=== Q3: афиша ===
-> Sort: m.title  (actual time=0.562..0.567 rows=22 loops=1)
    -> Stream results  (actual time=0.439..0.53 rows=22 loops=1)
        -> Group aggregate: group_concat(tmp_field order by screening.starts_at ASC separator ', ')  (actual time=0.435..0.499 rows=22 loops=1)
            -> Sort: m.id, m.title, m.age_rating, m.duration_min  (actual time=0.424..0.433 rows=30 loops=1)
                -> Stream results  (cost=5.25 rows=11.1) (actual time=0.117..0.359 rows=30 loops=1)
                    -> Nested loop inner join  (cost=5.25 rows=11.1) (actual time=0.105..0.284 rows=30 loops=1)
                        -> Filter: ((s.starts_at >= <cache>(curdate())) and (s.starts_at < <cache>((curdate() + interval 1 day))))  (cost=1.36 rows=11.1) (actual time=0.0851..0.133 rows=30 loops=1)
                            -> Table scan on s  (cost=1.36 rows=100) (actual time=0.0511..0.0914 rows=100 loops=1)
                        -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.259 rows=1) (actual time=0.00458..0.00462 rows=1 loops=30)

=== Q4: топ-3 фильма по выручке за неделю ===
-> Limit: 3 row(s)  (actual time=35.2..35.2 rows=3 loops=1)
    -> Sort: revenue DESC, limit input to 3 row(s) per chunk  (actual time=35.2..35.2 rows=3 loops=1)
        -> Table scan on <temporary>  (actual time=35.2..35.2 rows=45 loops=1)
            -> Aggregate using temporary table  (actual time=35.2..35.2 rows=45 loops=1)
                -> Nested loop inner join  (cost=2197 rows=2737) (actual time=0.398..18.3 rows=8069 loops=1)
                    -> Nested loop inner join  (cost=1239 rows=2737) (actual time=0.387..14 rows=8069 loops=1)
                        -> Filter: (t.purchased_at >= <cache>((now() - interval 7 day)))  (cost=281 rows=2737) (actual time=0.37..8.69 rows=8069 loops=1)
                            -> Table scan on t  (cost=281 rows=8212) (actual time=0.361..6.5 rows=8069 loops=1)
                        -> Single-row index lookup on s using PRIMARY (id=t.screening_id)  (cost=0.25 rows=1) (actual time=364e-6..398e-6 rows=1 loops=8069)
                    -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.25 rows=1) (actual time=232e-6..270e-6 rows=1 loops=8069)

=== Q5: схема зала ===
-> Group aggregate: group_concat(if((t.id is null),'o','X') order by st.seat_no ASC separator ' ')  (cost=110 rows=31.6) (actual time=0.264..1.5 rows=10 loops=1)
    -> Nested loop left join  (cost=90.3 rows=200) (actual time=0.0955..1.27 rows=200 loops=1)
        -> Covering index lookup on st using seat_index_2 (hall_id='1')  (cost=20.3 rows=200) (actual time=0.0808..0.232 rows=200 loops=1)
        -> Single-row covering index lookup on t using ticket_index_9 (screening_id=(@sid), seat_id=st.id)  (cost=0.251 rows=1) (actual time=0.00478..0.0048 rows=0.385 loops=200)

=== Q6: мин. и макс. цена на сеанс ===
-> Aggregate: min(screening_price.price), max(screening_price.price)  (cost=0.851 rows=1) (actual time=0.0246..0.0247 rows=1 loops=1)
    -> Index lookup on screening_price using PRIMARY (screening_id=(@sid))  (cost=0.551 rows=3) (actual time=0.0162..0.0193 rows=3 loops=1)

```

### 10 млн строк, без оптимизаций

```
sid = 67501
=== Q1: сеансы на сегодня ===
-> Sort: screening.starts_at  (cost=1053 rows=75000) (actual time=34.4..34.4 rows=180 loops=1)
    -> Filter: ((screening.starts_at >= <cache>(curdate())) and (screening.starts_at < <cache>((curdate() + interval 1 day))))  (cost=1053 rows=75000) (actual time=32.4..34.3 rows=180 loops=1)
        -> Table scan on screening  (cost=1053 rows=75000) (actual time=1.8..28.5 rows=75000 loops=1)

=== Q2: билеты за неделю ===
-> Aggregate: count(0)  (cost=555182 rows=1) (actual time=4091..4091 rows=1 loops=1)
    -> Filter: (ticket.purchased_at >= <cache>((now() - interval 7 day)))  (cost=290669 rows=2.65e+6) (actual time=3987..4081 rows=180396 loops=1)
        -> Table scan on ticket  (cost=290669 rows=7.94e+6) (actual time=0.755..3452 rows=8.17e+6 loops=1)

=== Q3: афиша ===
-> Sort: m.title  (actual time=41.2..41.2 rows=148 loops=1)
    -> Stream results  (actual time=40.9..41.1 rows=148 loops=1)
        -> Group aggregate: group_concat(tmp_field order by screening.starts_at ASC separator ', ')  (actual time=40.9..41.1 rows=148 loops=1)
            -> Sort: m.id, m.title, m.age_rating, m.duration_min  (actual time=40.9..40.9 rows=180 loops=1)
                -> Stream results  (cost=9524 rows=8332) (actual time=37.9..40.7 rows=180 loops=1)
                    -> Nested loop inner join  (cost=9524 rows=8332) (actual time=37.8..40.5 rows=180 loops=1)
                        -> Filter: ((s.starts_at >= <cache>(curdate())) and (s.starts_at < <cache>((curdate() + interval 1 day))))  (cost=1053 rows=8332) (actual time=37.7..39.7 rows=180 loops=1)
                            -> Table scan on s  (cost=1053 rows=75000) (actual time=1.52..32.2 rows=75000 loops=1)
                        -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.917 rows=1) (actual time=0.00423..0.00426 rows=1 loops=180)

=== Q4: топ-3 фильма по выручке за неделю ===
-> Limit: 3 row(s)  (actual time=5677..5677 rows=3 loops=1)
    -> Sort: revenue DESC, limit input to 3 row(s) per chunk  (actual time=5677..5677 rows=3 loops=1)
        -> Table scan on <temporary>  (actual time=5677..5677 rows=483 loops=1)
            -> Aggregate using temporary table  (actual time=5677..5677 rows=483 loops=1)
                -> Nested loop inner join  (cost=2.14e+6 rows=2.65e+6) (actual time=4744..5084 rows=180395 loops=1)
                    -> Nested loop inner join  (cost=1.21e+6 rows=2.65e+6) (actual time=4744..5015 rows=180395 loops=1)
                        -> Filter: (t.purchased_at >= <cache>((now() - interval 7 day)))  (cost=288587 rows=2.65e+6) (actual time=4744..4925 rows=180395 loops=1)
                            -> Table scan on t  (cost=288587 rows=7.94e+6) (actual time=1.06..4227 rows=8.17e+6 loops=1)
                        -> Single-row index lookup on s using PRIMARY (id=t.screening_id)  (cost=0.25 rows=1) (actual time=272e-6..299e-6 rows=1 loops=180395)
                    -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.25 rows=1) (actual time=153e-6..185e-6 rows=1 loops=180395)

=== Q5: схема зала ===
-> Group aggregate: group_concat(if((t.id is null),'o','X') order by st.seat_no ASC separator ' ')  (cost=243 rows=77.5) (actual time=0.146..0.931 rows=10 loops=1)
    -> Nested loop left join  (cost=223 rows=200) (actual time=0.0573..0.788 rows=200 loops=1)
        -> Covering index lookup on st using seat_index_2 (hall_id='1')  (cost=20.3 rows=200) (actual time=0.0494..0.0984 rows=200 loops=1)
        -> Single-row covering index lookup on t using ticket_index_9 (screening_id=(@sid), seat_id=st.id)  (cost=0.915 rows=1) (actual time=0.00324..0.00326 rows=0.605 loops=200)

=== Q6: мин. и макс. цена на сеанс ===
-> Aggregate: min(screening_price.price), max(screening_price.price)  (cost=1.6 rows=1) (actual time=0.0276..0.0278 rows=1 loops=1)
    -> Index lookup on screening_price using PRIMARY (screening_id=(@sid))  (cost=1.3 rows=3) (actual time=0.0189..0.0227 rows=3 loops=1)

```

### 10 млн строк, после оптимизаций

```
sid = 67524
=== Q1: сеансы на сегодня ===
-> Filter: ((screening.starts_at >= <cache>(curdate())) and (screening.starts_at < <cache>((curdate() + interval 1 day))))  (cost=37.4 rows=180) (actual time=0.0499..0.0981 rows=180 loops=1)
    -> Covering index range scan on screening using idx_screening_starts_at over ('2026-10-17 00:00:00' <= starts_at < '2026-10-18 00:00:00')  (cost=37.4 rows=180) (actual time=0.0471..0.0706 rows=180 loops=1)

=== Q2: билеты за неделю ===
-> Aggregate: count(0)  (cost=108459 rows=1) (actual time=66.9..66.9 rows=1 loops=1)
    -> Filter: (ticket.purchased_at >= <cache>((now() - interval 7 day)))  (cost=72569 rows=358904) (actual time=0.426..58.8 rows=180344 loops=1)
        -> Covering index range scan on ticket using idx_ticket_purchased over ('2026-10-10 08:06:53' <= purchased_at)  (cost=72569 rows=358904) (actual time=0.421..41.4 rows=180344 loops=1)

=== Q3: афиша ===
-> Sort: m.title  (actual time=1.14..1.16 rows=148 loops=1)
    -> Stream results  (actual time=0.827..1.07 rows=148 loops=1)
        -> Group aggregate: group_concat(tmp_field order by screening.starts_at ASC separator ', ')  (actual time=0.824..0.993 rows=148 loops=1)
            -> Sort: m.id, m.title, m.age_rating, m.duration_min  (actual time=0.817..0.836 rows=180 loops=1)
                -> Stream results  (cost=100 rows=180) (actual time=0.0677..0.672 rows=180 loops=1)
                    -> Nested loop inner join  (cost=100 rows=180) (actual time=0.0618..0.484 rows=180 loops=1)
                        -> Filter: ((s.starts_at >= <cache>(curdate())) and (s.starts_at < <cache>((curdate() + interval 1 day))))  (cost=37.4 rows=180) (actual time=0.0501..0.143 rows=180 loops=1)
                            -> Covering index range scan on s using idx_screening_starts_at over ('2026-10-17 00:00:00' <= starts_at < '2026-10-18 00:00:00')  (cost=37.4 rows=180) (actual time=0.0466..0.1 rows=180 loops=1)
                        -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.251 rows=1) (actual time=0.00169..0.00171 rows=1 loops=180)

=== Q4: топ-3 фильма по выручке за неделю ===
-> Limit: 3 row(s)  (actual time=1671..1671 rows=3 loops=1)
    -> Sort: revenue DESC, limit input to 3 row(s) per chunk  (actual time=1671..1671 rows=3 loops=1)
        -> Table scan on <temporary>  (actual time=1671..1671 rows=483 loops=1)
            -> Aggregate using temporary table  (actual time=1671..1671 rows=483 loops=1)
                -> Nested loop inner join  (cost=556641 rows=358904) (actual time=0.82..944 rows=180344 loops=1)
                    -> Nested loop inner join  (cost=431024 rows=358904) (actual time=0.817..608 rows=180344 loops=1)
                        -> Filter: (t.purchased_at >= <cache>((now() - interval 7 day)))  (cost=72569 rows=358904) (actual time=0.809..176 rows=180344 loops=1)
                            -> Covering index range scan on t using idx_ticket_purchased over ('2026-10-10 08:06:53' <= purchased_at)  (cost=72569 rows=358904) (actual time=0.806..131 rows=180344 loops=1)
                        -> Single-row index lookup on s using PRIMARY (id=t.screening_id)  (cost=0.899 rows=1) (actual time=0.00214..0.00217 rows=1 loops=180344)
                    -> Single-row index lookup on m using PRIMARY (id=s.movie_id)  (cost=0.25 rows=1) (actual time=0.0016..0.00164 rows=1 loops=180344)

=== Q5: схема зала ===
-> Group aggregate: group_concat(if((t.id is null),'o','X') order by st.seat_no ASC separator ' ')  (cost=261 rows=77.5) (actual time=0.146..0.872 rows=10 loops=1)
    -> Nested loop left join  (cost=241 rows=200) (actual time=0.0568..0.745 rows=200 loops=1)
        -> Covering index lookup on st using seat_index_2 (hall_id='24')  (cost=21 rows=200) (actual time=0.05..0.0944 rows=200 loops=1)
        -> Single-row covering index lookup on t using ticket_index_9 (screening_id=(@sid), seat_id=st.id)  (cost=1 rows=1) (actual time=0.00306..0.00307 rows=0.605 loops=200)

=== Q6: мин. и макс. цена на сеанс ===
-> Aggregate: min(screening_price.price), max(screening_price.price)  (cost=1.6 rows=1) (actual time=0.0255..0.0257 rows=1 loops=1)
    -> Index lookup on screening_price using PRIMARY (screening_id=(@sid))  (cost=1.3 rows=3) (actual time=0.0172..0.0205 rows=3 loops=1)

```

### Q4 переписанный (вариант 4b), 10 млн строк

```
-> Limit: 3 row(s)  (actual time=275..275 rows=3 loops=1)
    -> Sort: revenue DESC, limit input to 3 row(s) per chunk  (actual time=275..275 rows=3 loops=1)
        -> Table scan on <temporary>  (actual time=275..275 rows=483 loops=1)
            -> Aggregate using temporary table  (actual time=275..275 rows=483 loops=1)
                -> Nested loop inner join  (cost=185557 rows=0) (actual time=178..271 rows=1799 loops=1)
                    -> Nested loop inner join  (cost=7324 rows=71293) (actual time=0.084..30.5 rows=75000 loops=1)
                        -> Covering index scan on m using movie_index_0  (cost=52.5 rows=500) (actual time=0.026..0.271 rows=500 loops=1)
                        -> Covering index lookup on s using screening_index_7 (movie_id=m.id)  (cost=0.313 rows=143) (actual time=0.0268..0.0509 rows=150 loops=500)
                    -> Index lookup on x using <auto_key0> (screening_id=s.id)  (cost=0.25..2.5 rows=10) (actual time=0.00307..0.00308 rows=0.024 loops=75000)
                        -> Materialize  (cost=0..0 rows=0) (actual time=177..177 rows=1799 loops=1)
                            -> Table scan on <temporary>  (actual time=176..176 rows=1799 loops=1)
                                -> Aggregate using temporary table  (actual time=176..176 rows=1799 loops=1)
                                    -> Filter: (t.purchased_at >= <cache>((now() - interval 7 day)))  (cost=72243 rows=357292) (actual time=0.731..86.5 rows=180313 loops=1)
                                        -> Covering index range scan on t using idx_ticket_purchased over ('2026-10-10 08:08:51' <= purchased_at)  (cost=72243 rows=357292) (actual time=0.728..67.5 rows=180313 loops=1)

```
