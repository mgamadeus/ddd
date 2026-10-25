DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO `CounterRows` (`businessKey`, `amount`) VALUES (?, ?)
-- params: {"1":"shared","2":0}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"shared","2":20,"3":1,"4":0}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`, `displayOrder`, `id`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"shared","2":10,"3":1,"4":0,"5":7}
-- ----------------------------------------
SELECT * FROM `CounterRows`
-- params: []
-- ----------------------------------------
SELECT COUNT(*) FROM `CounterRows`
-- params: []
-- ----------------------------------------
