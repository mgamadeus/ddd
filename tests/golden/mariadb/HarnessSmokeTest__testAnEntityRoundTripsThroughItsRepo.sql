DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `counter`, `amount`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `counter` = `counter` + COALESCE(VALUES(`counter`), 1), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"smoke","2":3,"3":7,"4":1,"5":0}
-- ----------------------------------------
SELECT * FROM `CounterRows` WHERE id = ?
-- params: {"1":1}
-- ----------------------------------------
