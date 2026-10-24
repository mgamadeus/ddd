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
-- params: {"1":"before-kill"}
-- ----------------------------------------
SELECT CONNECTION_ID()
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey) VALUES (?)
-- params: {"1":"after-kill"}
-- ----------------------------------------
SELECT 1
-- params: []
-- ----------------------------------------
