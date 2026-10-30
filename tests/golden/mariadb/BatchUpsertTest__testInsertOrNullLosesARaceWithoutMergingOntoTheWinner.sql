DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`) VALUES (?, ?, ?)
-- params: {"1":"race","2":1,"3":1}
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `amount`, `isActive`) VALUES (?, ?, ?)
-- params: {"1":"race","2":999,"3":1}
-- ----------------------------------------
SELECT COUNT(*) FROM `CounterRows`
-- params: []
-- ----------------------------------------
SELECT * FROM `CounterRows`
-- params: []
-- ----------------------------------------
