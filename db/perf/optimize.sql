-- Оптимизации по результатам анализа планов на 10 млн строк.
-- Запуск: cmd /c "docker exec -i pspa-mysql mysql -uroot --default-character-set=utf8mb4 pspa_db < db\perf\optimize.sql"
--
-- Проблема: в запросах 1, 2, 3, 4 фильтр по дате (starts_at, purchased_at), но индексов по этим колонкам нет,
-- поэтому MySQL читает таблицу целиком (Table scan): 75 тыс. сеансов и 8,17 млн билетов ради 180 и 180 тыс. строк.
--
-- Запросы 5 и 6 уже используют индексы (ticket_index_9 и PRIMARY screening_price), для них изменения не нужны.

-- Запросы 1 и 3: диапазон по starts_at.
-- Индекс покрывающий: starts_at (фильтр и сортировка), movie_id и hall_id (нужны запросам), id входит в индекс автоматически.
-- Читать саму таблицу screening не нужно.
ALTER TABLE screening
  ADD INDEX idx_screening_starts_at (starts_at, movie_id, hall_id);

-- Запросы 2 и 4: диапазон по purchased_at.
-- Покрывающий индекс: purchased_at (диапазон), screening_id (JOIN), price_paid (SUM), id (COUNT) входит автоматически.
-- Запрос 2 считается по индексу, запрос 4 не читает таблицу ticket.
ALTER TABLE ticket
  ADD INDEX idx_ticket_purchased (purchased_at, screening_id, price_paid);

-- Свежая статистика для оптимизатора
ANALYZE TABLE screening, ticket;

-- Откат (для сравнения "до/после" на тех же данных):
-- ALTER TABLE screening DROP INDEX idx_screening_starts_at;
-- ALTER TABLE ticket    DROP INDEX idx_ticket_purchased;
