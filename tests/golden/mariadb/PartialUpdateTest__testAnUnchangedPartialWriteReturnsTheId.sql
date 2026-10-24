DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, label) VALUES (?, ?)
-- params: {"1":"b","2":"same"}
-- ----------------------------------------
UPDATE CounterRows SET `label` = ? WHERE `id` = ?
-- params: {"1":"same","2":2}
-- ----------------------------------------
SELECT 1 FROM CounterRows WHERE `id` = ?
-- params: {"1":2}
-- ----------------------------------------
SELECT COUNT(*) FROM CounterRows
-- params: []
-- ----------------------------------------
