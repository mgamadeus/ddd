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
