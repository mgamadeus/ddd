DROP TRIGGER IF EXISTS onTriggerRowInsert;
CREATE TRIGGER onTriggerRowInsert
    BEFORE INSERT
    ON TriggerRows
    FOR EACH ROW
BEGIN
    DECLARE existing_id INT DEFAULT NULL;
    IF NEW.active = 1 THEN
        SELECT id INTO existing_id
        FROM TriggerRows
        WHERE code = NEW.code
          AND active = 1
        LIMIT 1;
        IF existing_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DUPLICATE_CODE: an active TriggerRow with this code already exists';
        END IF;
    END IF;
END
