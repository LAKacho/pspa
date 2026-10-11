SET NAMES utf8mb4;

-- 1. Жанры, фильмы, связь фильм-жанр
INSERT INTO genre (id, name) VALUES
  (1, 'Фантастика'),
  (2, 'Боевик'),
  (3, 'Драма'),
  (4, 'Комедия');

INSERT INTO movie (id, title, duration_min, age_rating, description) VALUES
  (1, 'Космический рубеж', 140, '12+', 'Экспедиция на границе исследованного космоса'),
  (2, 'Тихая гавань',      105, '16+', 'История семьи в маленьком портовом городе'),
  (3, 'Смех до слёз',       95, '6+',  'Комедия положений'),
  (4, 'Последний рейс',    120, '16+', 'Погоня на ночном поезде');

INSERT INTO movie_genre (movie_id, genre_id) VALUES
  (1, 1), (1, 2),
  (2, 3),
  (3, 4),
  (4, 2), (4, 3);

-- 2. Залы и типы мест
INSERT INTO hall (id, name) VALUES
  (1, 'Зал 1'),
  (2, 'Зал 2');

INSERT INTO seat_type (id, name, description) VALUES
  (1, 'Обычное', 'Стандартное кресло'),
  (2, 'Диван',   'Двойной диван'),
  (3, 'VIP',     'Кресло-реклайнер');

-- 3. Места (id задаём явно, чтобы они были предсказуемыми)
-- Зал 1: 4 ряда по 6 мест. Ряды 1-2 обычные, ряд 3 диваны, ряд 4 VIP
INSERT INTO seat (id, hall_id, row_no, seat_no, seat_type_id)
SELECT (r.n - 1) * 6 + s.n,
       1,
       r.n,
       s.n,
       CASE r.n WHEN 4 THEN 3 WHEN 3 THEN 2 ELSE 1 END
FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) r
CROSS JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3
            UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) s;

-- Зал 2: 3 ряда по 5 мест. Ряды 1-2 обычные, ряд 3 VIP (диванов нет)
INSERT INTO seat (id, hall_id, row_no, seat_no, seat_type_id)
SELECT 24 + (r.n - 1) * 5 + s.n,
       2,
       r.n,
       s.n,
       CASE r.n WHEN 3 THEN 3 ELSE 1 END
FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3) r
CROSS JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3
            UNION ALL SELECT 4 UNION ALL SELECT 5) s;

-- 4. Сеансы (даты относительно текущего дня)
INSERT INTO screening (id, movie_id, hall_id, starts_at) VALUES
  (1, 1, 1, TIMESTAMP(CURDATE(),                  '12:00:00')),
  (2, 1, 1, TIMESTAMP(CURDATE(),                  '20:00:00')),
  (3, 2, 2, TIMESTAMP(CURDATE(),                  '14:00:00')),
  (4, 2, 2, TIMESTAMP(CURDATE(),                  '19:30:00')),
  (5, 3, 1, TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '10:00:00')),
  (6, 3, 2, TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '12:00:00')),
  (7, 1, 2, TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '20:30:00')),
  (8, 4, 1, TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '20:00:00'));

-- 5. Цены: по сеансу и типу места (только те типы, что есть в зале сеанса)
-- Зал 1 -> типы 1, 2, 3. Зал 2 -> типы 1, 3
INSERT INTO screening_price (screening_id, seat_type_id, price) VALUES
  (1, 1, 300), (1, 2, 400), (1, 3, 500),   -- дневной, зал 1
  (2, 1, 450), (2, 2, 600), (2, 3, 800),   -- вечерний, зал 1
  (3, 1, 250), (3, 3, 450),                -- дневной, зал 2
  (4, 1, 400), (4, 3, 700),                -- вечерний, зал 2
  (5, 1, 200), (5, 2, 280), (5, 3, 350),   -- дешёвая комедия, зал 1
  (6, 1, 200), (6, 3, 350),                -- дешёвая комедия, зал 2
  (7, 1, 450), (7, 3, 800),                -- вечерний, зал 2
  (8, 1, 400), (8, 2, 550), (8, 3, 700);   -- без продаж

-- 6. Клиенты
INSERT INTO customer (id, full_name, email, phone) VALUES
  (1, 'Иван Петров',      'ivan.petrov@example.com',   '+79001111111'),
  (2, 'Мария Сидорова',   'maria.sidorova@example.com','+79002222222'),
  (3, 'Алексей Кузнецов', 'a.kuznetsov@example.com',   '+79003333333'),
  (4, 'Елена Смирнова',   'e.smirnova@example.com',    NULL),
  (5, 'Дмитрий Орлов',    'd.orlov@example.com',       '+79005555555');

-- 7. Билеты: 30 штук.
-- В списке только (сеанс, ряд, место, клиент). Зал берётся из сеанса,
-- место ищется по залу, цена берётся из screening_price по типу места.
-- Поэтому hall_id и price_paid всегда согласованы с остальными таблицами.
INSERT INTO ticket (screening_id, seat_id, hall_id, customer_id, price_paid)
SELECT t.screening_id, s.id, s.hall_id, t.customer_id, sp.price
FROM (
  SELECT 1 AS screening_id, 1 AS row_no, 3 AS seat_no, 1 AS customer_id
  UNION ALL SELECT 1, 3, 2, 2
  UNION ALL SELECT 1, 4, 5, 3
  UNION ALL SELECT 2, 2, 2, 1
  UNION ALL SELECT 2, 3, 3, 4
  UNION ALL SELECT 2, 4, 1, 5
  UNION ALL SELECT 2, 4, 2, 5
  UNION ALL SELECT 2, 3, 4, 2
  UNION ALL SELECT 7, 3, 1, 3
  UNION ALL SELECT 7, 3, 2, 3
  UNION ALL SELECT 7, 1, 2, 4
  UNION ALL SELECT 3, 1, 1, 2
  UNION ALL SELECT 3, 2, 4, 4
  UNION ALL SELECT 3, 3, 5, 1
  UNION ALL SELECT 4, 2, 3, 5
  UNION ALL SELECT 4, 3, 3, 3
  UNION ALL SELECT 5, 1, 1, 1
  UNION ALL SELECT 5, 1, 2, 1
  UNION ALL SELECT 5, 1, 3, 2
  UNION ALL SELECT 5, 1, 4, 2
  UNION ALL SELECT 5, 2, 1, 3
  UNION ALL SELECT 5, 2, 2, 4
  UNION ALL SELECT 5, 2, 3, 5
  UNION ALL SELECT 5, 2, 4, 5
  UNION ALL SELECT 5, 3, 1, 5
  UNION ALL SELECT 5, 4, 6, 5
  UNION ALL SELECT 6, 1, 1, 3
  UNION ALL SELECT 6, 1, 2, 4
  UNION ALL SELECT 6, 2, 5, 4
  UNION ALL SELECT 6, 3, 2, 1
) t
JOIN screening       sc ON sc.id = t.screening_id
JOIN seat            s  ON s.hall_id = sc.hall_id
                       AND s.row_no = t.row_no
                       AND s.seat_no = t.seat_no
JOIN screening_price sp ON sp.screening_id = sc.id
                       AND sp.seat_type_id = s.seat_type_id;