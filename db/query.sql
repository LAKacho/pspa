-- Самый прибыльный фильм: сумма фактически уплаченных цен по всем билетам фильма
SELECT m.id,
       m.title,
       COUNT(t.id)       AS tickets_sold,
       SUM(t.price_paid) AS revenue
FROM ticket t
JOIN screening s ON s.id = t.screening_id
JOIN movie m     ON m.id = s.movie_id
GROUP BY m.id, m.title
ORDER BY revenue DESC
LIMIT 1;
