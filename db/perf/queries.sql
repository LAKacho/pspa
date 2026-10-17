-- Шесть основных запросов кинотеатра (MySQL 8.0).
-- Простые (одна таблица): 1, 2, 6. Сложные (JOIN + агрегаты): 3, 4, 5.
--
-- Условия по датам написаны диапазоном (>= и <), без DATE(колонка) или других функций над колонкой:
-- иначе MySQL не сможет использовать индекс по этой колонке.
--
-- Для запросов 5 и 6 нужен конкретный сеанс: берём самый ранний сеанс сегодняшнего дня.
-- Выполните эту строку один раз в сессии перед запросами 5 и 6.
SET @sid = (SELECT id FROM screening
            WHERE starts_at >= CURDATE() AND starts_at < CURDATE() + INTERVAL 1 DAY
            ORDER BY starts_at LIMIT 1);

-- 1. Все сеансы на сегодня (простой, таблица screening)
SELECT id, movie_id, hall_id, starts_at
FROM screening
WHERE starts_at >= CURDATE()
  AND starts_at <  CURDATE() + INTERVAL 1 DAY
ORDER BY starts_at;

-- 2. Количество билетов, проданных за неделю (простой, таблица ticket)
SELECT COUNT(*) AS tickets_week
FROM ticket
WHERE purchased_at >= NOW() - INTERVAL 7 DAY;

-- 3. Афиша: фильмы, которые показывают сегодня, со временем сеансов (сложный)
SELECT m.id,
       m.title,
       m.age_rating,
       m.duration_min,
       GROUP_CONCAT(TIME_FORMAT(s.starts_at, '%H:%i') ORDER BY s.starts_at SEPARATOR ', ') AS sessions
FROM screening s
JOIN movie m ON m.id = s.movie_id
WHERE s.starts_at >= CURDATE()
  AND s.starts_at <  CURDATE() + INTERVAL 1 DAY
GROUP BY m.id, m.title, m.age_rating, m.duration_min
ORDER BY m.title;

-- 4. Три самых прибыльных фильма за неделю (сложный)
SELECT m.id,
       m.title,
       COUNT(t.id)       AS tickets_sold,
       SUM(t.price_paid) AS revenue
FROM ticket t
JOIN screening s ON s.id = t.screening_id
JOIN movie m     ON m.id = s.movie_id
WHERE t.purchased_at >= NOW() - INTERVAL 7 DAY
GROUP BY m.id, m.title
ORDER BY revenue DESC
LIMIT 3;

-- 5. Схема зала на сеанс @sid: по строке на ряд, o = свободно, X = занято (сложный)
SELECT st.row_no,
       GROUP_CONCAT(IF(t.id IS NULL, 'o', 'X') ORDER BY st.seat_no SEPARATOR ' ') AS seats
FROM screening sc
JOIN seat st        ON st.hall_id = sc.hall_id
LEFT JOIN ticket t  ON t.screening_id = sc.id
                   AND t.seat_id = st.id
WHERE sc.id = @sid
GROUP BY st.row_no
ORDER BY st.row_no;

-- 6. Минимальная и максимальная цена билета на сеанс @sid (простой, таблица screening_price)
SELECT MIN(price) AS min_price,
       MAX(price) AS max_price
FROM screening_price
WHERE screening_id = @sid;

-- 4b. Тот же запрос 4, переписанный: сначала билеты группируются по сеансу (сотни строк),
-- и только потом присоединяются screening и movie. Результат тот же, но join идёт по ~1,8 тыс. групп,
-- а не по 180 тыс. строк билетов.
SELECT m.id,
       m.title,
       SUM(x.cnt)     AS tickets_sold,
       SUM(x.revenue) AS revenue
FROM (
  SELECT t.screening_id, COUNT(*) AS cnt, SUM(t.price_paid) AS revenue
  FROM ticket t
  WHERE t.purchased_at >= NOW() - INTERVAL 7 DAY
  GROUP BY t.screening_id
) x
JOIN screening s ON s.id = x.screening_id
JOIN movie m     ON m.id = s.movie_id
GROUP BY m.id, m.title
ORDER BY revenue DESC
LIMIT 3;
