DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `counter`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `counter` = `counter` + COALESCE(VALUES(`counter`), 1), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"cell-1","2":2,"3":1,"4":0,"5":"cell-2","6":3,"7":1,"8":0}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `counter`, `isActive`, `displayOrder`) VALUES (?, ?, ?, ?), (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `businessKey` = VALUES(`businessKey`), `counter` = `counter` + COALESCE(VALUES(`counter`), 1), `isActive` = VALUES(`isActive`), `displayOrder` = VALUES(`displayOrder`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"cell-1","2":5,"3":1,"4":0,"5":"cell-3","6":1,"7":1,"8":0}
-- ----------------------------------------
SELECT * FROM `CounterRows`
-- params: []
-- ----------------------------------------
