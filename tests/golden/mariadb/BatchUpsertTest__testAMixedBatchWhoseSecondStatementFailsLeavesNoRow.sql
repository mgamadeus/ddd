DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"first","2":1,"3":1,"4":0}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `label`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `label` = VALUES(`label`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"second","2":"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx","3":1,"4":0}
-- ----------------------------------------
SELECT * FROM CounterRows
-- params: []
-- ----------------------------------------
