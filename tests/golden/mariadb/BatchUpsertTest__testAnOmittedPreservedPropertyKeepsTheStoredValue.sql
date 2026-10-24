DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, displayOrder, amount) VALUES (?, ?, ?)
-- params: {"1":"kept","2":9,"3":1}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`, `id`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"kept","2":2,"3":1,"4":1}
-- ----------------------------------------
SELECT * FROM CounterRows
-- params: []
-- ----------------------------------------
