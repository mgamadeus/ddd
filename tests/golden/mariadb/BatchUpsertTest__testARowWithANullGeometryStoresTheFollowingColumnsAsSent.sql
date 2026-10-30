DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO SpatialRows (`label`, `location`, `embedding`, `flag`, `payload`, `amount`) VALUES (?, ?, VEC_FromText(?), ?, ?, ?) ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `location` = VALUES(`location`), `embedding` = VALUES(`embedding`), `flag` = VALUES(`flag`), `payload` = VALUES(`payload`), `amount` = VALUES(`amount`), `id` = LAST_INSERT_ID(`id`)
-- params: {"1":"no location","2":null,"3":"[0.5,0.25,0.125]","4":1,"5":"{\"color\":\"red\",\"size\":3,\"objectType\":\"DDD\\\\Tests\\\\Integration\\\\Fixtures\\\\Entities\\\\FixtureSettings\"}","6":42}
-- ----------------------------------------
SELECT label, location, flag, payload, amount, VEC_ToText(embedding) AS embedding FROM `SpatialRows`
-- params: []
-- ----------------------------------------
