-- Служебные отчёты.
-- Запуск: cmd /c "docker exec -i pspa-mysql mysql -uroot --default-character-set=utf8mb4 -t pspa_db < db\perf\admin.sql > db\perf\admin_report.txt"

-- Актуальная статистика размеров
ANALYZE TABLE movie, genre, movie_genre, hall, seat_type, seat, screening, screening_price, customer, ticket;

-- 1. 15 самых больших объектов БД: таблицы (данные + индексы) и отдельные индексы, по убыванию размера
SELECT *
FROM (
  SELECT 'таблица (данные + индексы)' AS object_type,
         table_name                   AS object_name,
         ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'

  UNION ALL

  SELECT 'индекс',
         CONCAT(table_name, '.', index_name),
         ROUND(stat_value * @@innodb_page_size / 1024 / 1024, 2)
  FROM mysql.innodb_index_stats
  WHERE database_name = DATABASE() AND stat_name = 'size'
) objects
ORDER BY size_mb DESC
LIMIT 15;

-- 2. Пять самых часто используемых индексов (по числу чтений)
SELECT object_name AS table_name, index_name,
       count_read, count_write
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = DATABASE() AND index_name IS NOT NULL
ORDER BY count_read DESC
LIMIT 5;

-- 3. Пять самых редко используемых индексов (по числу чтений, нули тоже входят)
SELECT object_name AS table_name, index_name,
       count_read, count_write
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = DATABASE() AND index_name IS NOT NULL
ORDER BY count_read ASC, count_write DESC
LIMIT 5;
