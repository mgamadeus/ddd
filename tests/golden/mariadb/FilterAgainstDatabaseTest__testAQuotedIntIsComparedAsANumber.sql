DELETE FROM `CounterRows`
-- params: []
-- ----------------------------------------
DELETE FROM `TranslatedRows`
-- params: []
-- ----------------------------------------
DELETE FROM `SpatialRows`
-- params: []
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, amount, isActive, occurredAt) VALUES (?, ?, ?, ?)
-- params: {"1":"a","2":4,"3":1,"4":null}
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, amount, isActive, occurredAt) VALUES (?, ?, ?, ?)
-- params: {"1":"b","2":14,"3":0,"4":"<<DATETIME>>"}
-- ----------------------------------------
INSERT INTO CounterRows (businessKey, amount, isActive, occurredAt) VALUES (?, ?, ?, ?)
-- params: {"1":"c","2":20,"3":1,"4":"<<DATETIME>>"}
-- ----------------------------------------
INSERT INTO TranslatedRows (name, title) VALUES (?, ?)
-- params: {"1":"{\"en\":\"Pizza\"}","2":"{\"en\":\"Zebra pizza\"}"}
-- ----------------------------------------
INSERT INTO TranslatedRows (name, title) VALUES (?, ?)
-- params: {"1":"{\"en\":\"Pasta\",\"de\":\"Nudeln\"}","2":"{\"en\":\"Apple\"}"}
-- ----------------------------------------
INSERT INTO TranslatedRows (name, title) VALUES (?, ?)
-- params: {"1":null,"2":"{\"en\":\"Mango\"}"}
-- ----------------------------------------
SELECT c0_.businessKey AS businessKey_0, c0_.counter AS counter_1, c0_.keptValue AS keptValue_2, c0_.label AS label_3, c0_.amount AS amount_4, c0_.ratio AS ratio_5, c0_.isActive AS isActive_6, c0_.dueOn AS dueOn_7, c0_.occurredAt AS occurredAt_8, c0_.displayOrder AS displayOrder_9, c0_.id AS id_10 FROM CounterRows c0_ WHERE c0_.amount = ? ORDER BY c0_.businessKey ASC
-- params: {"1":14}
-- ----------------------------------------
SELECT c0_.businessKey AS businessKey_0, c0_.counter AS counter_1, c0_.keptValue AS keptValue_2, c0_.label AS label_3, c0_.amount AS amount_4, c0_.ratio AS ratio_5, c0_.isActive AS isActive_6, c0_.dueOn AS dueOn_7, c0_.occurredAt AS occurredAt_8, c0_.displayOrder AS displayOrder_9, c0_.id AS id_10 FROM CounterRows c0_ WHERE c0_.amount > ? ORDER BY c0_.businessKey ASC
-- params: {"1":9}
-- ----------------------------------------
SELECT c0_.businessKey AS businessKey_0, c0_.counter AS counter_1, c0_.keptValue AS keptValue_2, c0_.label AS label_3, c0_.amount AS amount_4, c0_.ratio AS ratio_5, c0_.isActive AS isActive_6, c0_.dueOn AS dueOn_7, c0_.occurredAt AS occurredAt_8, c0_.displayOrder AS displayOrder_9, c0_.id AS id_10 FROM CounterRows c0_ WHERE c0_.amount IN (?, ?) ORDER BY c0_.businessKey ASC
-- params: {"1":4,"2":20}
-- ----------------------------------------
