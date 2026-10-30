DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
UPDATE CounterRows SET `label` = ? WHERE `id` = ?
-- params: {"1":"ghost","2":424242}
-- ----------------------------------------
SELECT 1 FROM CounterRows WHERE `id` = ?
-- params: {"1":424242}
-- ----------------------------------------
SELECT COUNT(*) FROM `CounterRows`
-- params: []
-- ----------------------------------------
