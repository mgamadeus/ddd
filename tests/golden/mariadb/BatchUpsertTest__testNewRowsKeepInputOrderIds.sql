DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `amount` = VALUES(`amount`), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"B","2":1,"3":1,"4":0,"5":"A","6":2,"7":1,"8":0}
-- ----------------------------------------
SELECT * FROM `CounterRows`
-- params: []
-- ----------------------------------------
