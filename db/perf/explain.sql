-- Планы выполнения всех 6 запросов (EXPLAIN ANALYZE показывает реальное время и число строк).
-- Запуск: см. db/perf/README или команду в чате; вывод сохраняется в db/perf/plans/<папка>/plans.txt
SET @sid = (SELECT id FROM screening
            WHERE starts_at >= CURDATE() AND starts_at < CURDATE() + INTERVAL 1 DAY
            ORDER BY starts_at LIMIT 1);
SELECT CONCAT('sid = ', @sid) AS info;

SELECT '=== Q1: сеансы на сегодня ===' AS q;
EXPLAIN ANALYZE
SELECT id, movie_id, hall_id, starts_at
FROM screening
WHERE starts_at >= CURDATE()
  AND starts_at <  CURDATE() + INTERVAL 1 DAY
ORDER BY starts_at;

SELECT '=== Q2: билеты за неделю ===' AS q;
EXPLAIN ANALYZE
SELECT COUNT(*) AS tickets_week
FROM ticket
WHERE purchased_at >= NOW() - INTERVAL 7 DAY;

SELECT '=== Q3: афиша ===' AS q;
EXPLAIN ANALYZE
SELECT m.id, m.title, m.age_rating, m.duration_min,
       GROUP_CONCAT(TIME_FORMAT(s.starts_at, '%H:%i') ORDER BY s.starts_at SEPARATOR ', ') AS sessions
FROM screening s
JOIN movie m ON m.id = s.movie_id
WHERE s.starts_at >= CURDATE()
  AND s.starts_at <  CURDATE() + INTERVAL 1 DAY
GROUP BY m.id, m.title, m.age_rating, m.duration_min
ORDER BY m.title;

SELECT '=== Q4: топ-3 фильма по выручке за неделю ===' AS q;
EXPLAIN ANALYZE
SELECT m.id, m.title, COUNT(t.id) AS tickets_sold, SUM(t.price_paid) AS revenue
FROM ticket t
JOIN screening s ON s.id = t.screening_id
JOIN movie m     ON m.id = s.movie_id
WHERE t.purchased_at >= NOW() - INTERVAL 7 DAY
GROUP BY m.id, m.title
ORDER BY revenue DESC
LIMIT 3;

SELECT '=== Q5: схема зала ===' AS q;
EXPLAIN ANALYZE
SELECT st.row_no,
       GROUP_CONCAT(IF(t.id IS NULL, 'o', 'X') ORDER BY st.seat_no SEPARATOR ' ') AS seats
FROM screening sc
JOIN seat st        ON st.hall_id = sc.hall_id
LEFT JOIN ticket t  ON t.screening_id = sc.id AND t.seat_id = st.id
WHERE sc.id = @sid
GROUP BY st.row_no
ORDER BY st.row_no;

SELECT '=== Q6: мин. и макс. цена на сеанс ===' AS q;
EXPLAIN ANALYZE
SELECT MIN(price) AS min_price, MAX(price) AS max_price
FROM screening_price
WHERE screening_id = @sid;
