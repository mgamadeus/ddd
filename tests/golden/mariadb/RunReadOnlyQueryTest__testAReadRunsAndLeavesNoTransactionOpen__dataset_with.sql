DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, amount) VALUES ('probe', 1)
-- params: []
-- ----------------------------------------
DROP FUNCTION IF EXISTS ddd_it_write_counter_row
-- params: []
-- ----------------------------------------
CREATE FUNCTION ddd_it_write_counter_row() RETURNS INT MODIFIES SQL DATA BEGIN INSERT INTO CounterRows (businessKey) VALUES (UUID()); RETURN 1; END
-- params: []
-- ----------------------------------------
START TRANSACTION READ ONLY
-- params: []
-- ----------------------------------------
WITH numbers AS (SELECT 1 AS one) SELECT * FROM numbers LIMIT 200
-- params: []
-- ----------------------------------------
ROLLBACK
-- params: []
-- ----------------------------------------
SELECT @@in_transaction
-- params: []
-- ----------------------------------------
DROP FUNCTION IF EXISTS ddd_it_write_counter_row
-- params: []
-- ----------------------------------------
