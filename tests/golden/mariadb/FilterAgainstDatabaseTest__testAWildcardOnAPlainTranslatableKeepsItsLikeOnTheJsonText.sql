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
SELECT id FROM TranslatedRows ORDER BY id
-- params: []
-- ----------------------------------------
SELECT t0_.name AS name_0, t0_.title AS title_1, t0_.settings AS settings_2, t0_.id AS id_3, t0_.virtualNameSearch AS virtualNameSearch_4 FROM TranslatedRows t0_ WHERE t0_.title LIKE ? ORDER BY t0_.id ASC
-- params: {"1":"%piz%"}
-- ----------------------------------------
