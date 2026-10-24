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
-- params: {"1":"request-write"}
-- ----------------------------------------
INSERT INTO CounterRows (businessKey) VALUES (?)
-- params: {"1":"handler-write"}
-- ----------------------------------------
