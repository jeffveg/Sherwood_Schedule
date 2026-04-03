-- Default availability schedule for Sherwood Adventure
-- Run once after schema.sql: mysql ... < db/seed_availability.sql
--
-- Schedule:
--   Sunday     0800-2100
--   Monday     1700-2100
--   Tuesday    1700-2100
--   Wednesday  1700-2100
--   Thursday   1700-2100
--   Friday     1700-0200 (crosses midnight into Saturday)
--   Saturday   0800-2359

-- Clear existing rules first (safe to re-run)
DELETE FROM availability_rules;

INSERT INTO availability_rules (day_of_week, open_time, close_time, crosses_midnight, active) VALUES
-- Sunday (0)
(0, '08:00:00', '21:00:00', 0, 1),
-- Monday (1)
(1, '17:00:00', '21:00:00', 0, 1),
-- Tuesday (2)
(2, '17:00:00', '21:00:00', 0, 1),
-- Wednesday (3)
(3, '17:00:00', '21:00:00', 0, 1),
-- Thursday (4)
(4, '17:00:00', '21:00:00', 0, 1),
-- Friday (5) — 5pm Friday to 2am Saturday
(5, '17:00:00', '02:00:00', 1, 1),
-- Saturday (6)
(6, '08:00:00', '23:59:00', 0, 1);
