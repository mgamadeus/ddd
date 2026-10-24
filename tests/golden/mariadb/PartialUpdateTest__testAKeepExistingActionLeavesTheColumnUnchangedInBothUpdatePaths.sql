DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, keptValue) VALUES (?, ?)
-- params: {"1":"e","2":11}
-- ----------------------------------------
UPDATE CounterRows SET `keptValue` = `keptValue` WHERE `id` = ?
-- params: {"1":5}
-- ----------------------------------------
SELECT 1 FROM CounterRows WHERE `id` = ?
-- params: {"1":5}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":5}
-- ----------------------------------------
UPDATE CounterRows SET `keptValue` = `keptValue`, `isActive` = ? WHERE `id` = ? AND `id` IN (SELECT * FROM (SELECT c0_.id AS id_0 FROM CounterRows c0_) dddUpdateRightsScope)
-- params: {"1":1,"2":5}
-- ----------------------------------------
SELECT c0_.businessKey AS businessKey_0, c0_.counter AS counter_1, c0_.keptValue AS keptValue_2, c0_.label AS label_3, c0_.amount AS amount_4, c0_.ratio AS ratio_5, c0_.isActive AS isActive_6, c0_.dueOn AS dueOn_7, c0_.occurredAt AS occurredAt_8, c0_.displayOrder AS displayOrder_9, c0_.id AS id_10 FROM CounterRows c0_ WHERE c0_.id = ? LIMIT 1
-- params: {"1":5}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":5}
-- ----------------------------------------
