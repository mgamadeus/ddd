DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (`businessKey`, `label`, `isActive`) VALUES (?, ?, ?)
-- params: {"1":"too-long-label","2":"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx","3":1}
-- ----------------------------------------
