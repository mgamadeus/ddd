DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO `CounterRows` (`businessKey`, `counter`) VALUES (?, ?)
-- params: {"1":"c","2":100}
-- ----------------------------------------
UPDATE CounterRows SET `counter` = `counter` + COALESCE((?), 1) WHERE `id` = ?
-- params: {"1":5,"2":3}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":3}
-- ----------------------------------------
