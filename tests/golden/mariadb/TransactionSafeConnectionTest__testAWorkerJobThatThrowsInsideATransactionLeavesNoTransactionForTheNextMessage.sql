SELECT 1
-- params: []
-- ----------------------------------------
DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey) VALUES (?)
-- params: {"1":"leaked"}
-- ----------------------------------------
INSERT INTO CounterRows (businessKey) VALUES (?)
-- params: {"1":"next-message"}
-- ----------------------------------------
