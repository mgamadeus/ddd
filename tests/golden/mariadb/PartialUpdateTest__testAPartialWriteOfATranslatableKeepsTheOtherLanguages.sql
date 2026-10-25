DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO `TranslatedRows` (`name`) VALUES (?)
-- params: {"1":"{\"en\":\"Bread\",\"fr\":\"Pain\"}"}
-- ----------------------------------------
UPDATE TranslatedRows SET `name` = JSON_MERGE_PATCH(COALESCE(`name`,'{}'), ?) WHERE `id` = ?
-- params: {"1":"{\"de::FORMAL\":\"Brot\"}","2":1}
-- ----------------------------------------
SELECT * FROM `TranslatedRows` WHERE id = ?
-- params: {"1":1}
-- ----------------------------------------
