DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, counter) VALUES (?, ?)
-- params: {"1":"d","2":100}
-- ----------------------------------------
UPDATE CounterRows SET `counter` = `counter` + COALESCE((?), 1), `isActive` = ? WHERE `id` = ? AND `id` IN (SELECT * FROM (SELECT c0_.id AS id_0 FROM CounterRows c0_) dddUpdateRightsScope)
-- params: {"1":5,"2":1,"3":4}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":4}
-- ----------------------------------------
