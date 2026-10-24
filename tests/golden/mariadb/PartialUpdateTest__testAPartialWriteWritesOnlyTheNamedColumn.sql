DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, label, amount, displayOrder) VALUES (?, ?, ?, ?)
-- params: {"1":"a","2":"before","3":7,"4":3}
-- ----------------------------------------
UPDATE CounterRows SET `label` = ? WHERE `id` = ?
-- params: {"1":"after","2":1}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":1}
-- ----------------------------------------
