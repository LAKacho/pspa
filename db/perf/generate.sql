-- Генератор тестовых данных для замеров производительности (MySQL 8.0).
--
-- ВНИМАНИЕ: скрипт ОЧИЩАЕТ все таблицы базы и создаёт данные заново (в том числе стартовые из 02_seed.sql).
-- Чтобы вернуть исходные данные: docker compose -f docker-compose.yml down -v, затем up -d.
--
-- Выберите масштаб в строке SET @scale ниже: 'small' (около 10 тыс. строк) или 'big' (около 10 млн строк).
--   docker exec -i pspa-mysql mysql -uroot pspa_db < db/perf/generate.sql
--
-- Структура данных:
--   зал = 10 рядов x 20 мест = 200 мест (ряды 1-7 обычные, 8-9 диваны, 10 VIP);
--   6 сеансов в день в каждом зале, сеансы идут по дням подряд, примерно 90% дней в прошлом, остальные в будущем;
--   билеты есть только на сеансы, которые начинаются не позже чем через 3 дня (позже продажи не открыты);
--   время покупки: до 3 суток до сеанса (или до текущего момента, если сеанс ещё не начался).

SET @scale = 'big';        -- 'small' или 'big'

SET @halls      = IF(@scale = 'big', 30,      5);
SET @screenings = IF(@scale = 'big', 75000,   100);
SET @movies     = IF(@scale = 'big', 500,     50);
SET @customers  = IF(@scale = 'big', 1800000, 1000);
SET @fill       = IF(@scale = 'big', 0.60,    0.40);   -- доля проданных мест на сеанс

SET @slots_per_day = 6;
SET @days  = CEIL(@screenings / (@halls * @slots_per_day));
SET @back  = LEAST(FLOOR(@days * 0.9), @days - 2);    -- на сколько дней назад начинаются сеансы (сегодня и завтра всегда есть)

SET SESSION foreign_key_checks = 0;
SET SESSION unique_checks = 0;

TRUNCATE TABLE ticket;
TRUNCATE TABLE screening_price;
TRUNCATE TABLE screening;
TRUNCATE TABLE seat;
TRUNCATE TABLE customer;
TRUNCATE TABLE movie_genre;
TRUNCATE TABLE movie;
TRUNCATE TABLE seat_type;
TRUNCATE TABLE genre;
TRUNCATE TABLE hall;

-- Вспомогательная таблица чисел 0..1999999 (после загрузки удаляется)
DROP TABLE IF EXISTS nums;
CREATE TABLE nums (n INT PRIMARY KEY);
INSERT INTO nums (n)
SELECT a.d + 10*b.d + 100*c.d + 1000*d.d + 10000*e.d + 100000*f.d + 1000000*g.d
FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e,
     (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f,
     (SELECT 0 d UNION ALL SELECT 1) g
WHERE g.d < 2
  AND a.d + 10*b.d + 100*c.d + 1000*d.d + 10000*e.d + 100000*f.d + 1000000*g.d < 2000000;

-- Справочники
INSERT INTO genre (id, name) VALUES (1, 'Фантастика'), (2, 'Боевик'), (3, 'Драма'), (4, 'Комедия');
INSERT INTO seat_type (id, name, description) VALUES
  (1, 'Обычное', 'Стандартное кресло'),
  (2, 'Диван',   'Двойной диван'),
  (3, 'VIP',     'Кресло-реклайнер');

INSERT INTO hall (id, name)
SELECT n + 1, CONCAT('Зал ', n + 1) FROM nums WHERE n < @halls;

INSERT INTO seat (hall_id, row_no, seat_no, seat_type_id)
SELECT h.n + 1, r.n + 1, s.n + 1,
       CASE WHEN r.n + 1 = 10 THEN 3 WHEN r.n + 1 >= 8 THEN 2 ELSE 1 END
FROM nums h
JOIN nums r ON r.n < 10
JOIN nums s ON s.n < 20
WHERE h.n < @halls;

-- Фильмы и жанры
INSERT INTO movie (id, title, duration_min, age_rating, description)
SELECT n + 1,
       CONCAT('Фильм ', n + 1),
       80 + (n * 7) % 100,
       ELT(1 + n % 5, '0+', '6+', '12+', '16+', '18+'),
       CONCAT('Описание фильма ', n + 1, '. ', REPEAT('Текст описания. ', 5))
FROM nums WHERE n < @movies;

INSERT INTO movie_genre (movie_id, genre_id)
SELECT n + 1, 1 + n % 4 FROM nums WHERE n < @movies;
INSERT INTO movie_genre (movie_id, genre_id)
SELECT n + 1, 1 + (n + 1) % 4 FROM nums WHERE n < @movies AND n % 3 = 0;

-- Клиенты
INSERT INTO customer (id, full_name, email, phone)
SELECT n + 1,
       CONCAT('Клиент ', n + 1),
       CONCAT('user', n + 1, '@example.com'),
       CONCAT('+7900', LPAD(n % 10000000, 7, '0'))
FROM nums WHERE n < @customers;

-- Сеансы: сеанс n идёт в зале (n mod залов), слот дня = (n div залов) mod 6
INSERT INTO screening (id, movie_id, hall_id, starts_at)
SELECT n + 1,
       1 + FLOOR(RAND() * @movies),
       1 + n % @halls,
       TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL (n DIV (@halls * @slots_per_day)) - @back DAY),
                 ELT(1 + (n DIV @halls) % @slots_per_day,
                     '10:00:00', '12:30:00', '15:00:00', '17:30:00', '20:00:00', '22:30:00'))
FROM nums WHERE n < @screenings;

-- Цены: зависят от слота (вечер дороже) и типа места
INSERT INTO screening_price (screening_id, seat_type_id, price)
SELECT sc.id, st.id,
       ROUND((200 + IF(((sc.id - 1) DIV @halls) % @slots_per_day >= 3, 150, 0)) * ELT(st.id, 1.0, 1.4, 1.8), 0)
FROM screening sc
CROSS JOIN seat_type st;

-- Билеты пачками по сеансам (чтобы не раздувать транзакцию)
DROP PROCEDURE IF EXISTS gen_tickets;
DELIMITER //
CREATE PROCEDURE gen_tickets()
BEGIN
  DECLARE a INT DEFAULT 1;
  DECLARE batch INT DEFAULT 1000;
  WHILE a <= @screenings DO
    INSERT INTO ticket (screening_id, seat_id, hall_id, customer_id, price_paid, purchased_at)
    SELECT sc.id, s.id, s.hall_id,
           1 + FLOOR(RAND() * @customers),
           sp.price,
           LEAST(sc.starts_at, NOW()) - INTERVAL FLOOR(RAND() * 259200) SECOND
    FROM screening sc
    JOIN seat s             ON s.hall_id = sc.hall_id
    JOIN screening_price sp ON sp.screening_id = sc.id AND sp.seat_type_id = s.seat_type_id
    WHERE sc.id BETWEEN a AND a + batch - 1
      AND sc.starts_at <= NOW() + INTERVAL 3 DAY
      AND RAND() < @fill;
    SET a = a + batch;
  END WHILE;
END//
DELIMITER ;
CALL gen_tickets();
DROP PROCEDURE gen_tickets;

DROP TABLE nums;
SET SESSION unique_checks = 1;
SET SESSION foreign_key_checks = 1;

-- Свежая статистика для оптимизатора
ANALYZE TABLE movie, genre, movie_genre, hall, seat_type, seat, screening, screening_price, customer, ticket;

-- Итог
SELECT 'movie' AS tbl, COUNT(*) AS cnt FROM movie
UNION ALL SELECT 'seat',            COUNT(*) FROM seat
UNION ALL SELECT 'screening',       COUNT(*) FROM screening
UNION ALL SELECT 'screening_price', COUNT(*) FROM screening_price
UNION ALL SELECT 'customer',        COUNT(*) FROM customer
UNION ALL SELECT 'ticket',          COUNT(*) FROM ticket
UNION ALL SELECT 'ВСЕГО строк', (SELECT COUNT(*) FROM movie) + (SELECT COUNT(*) FROM movie_genre)
        + (SELECT COUNT(*) FROM hall) + (SELECT COUNT(*) FROM seat) + (SELECT COUNT(*) FROM screening)
        + (SELECT COUNT(*) FROM screening_price) + (SELECT COUNT(*) FROM customer) + (SELECT COUNT(*) FROM ticket);
