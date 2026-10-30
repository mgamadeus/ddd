CREATE OR REPLACE FUNCTION ddd_trigger_triggerrows_before_insert() RETURNS trigger
    LANGUAGE plpgsql
    AS $ddd$
BEGIN
    IF NEW."active" IS TRUE THEN
        PERFORM 1 FROM "TriggerRows" WHERE "code" = NEW."code" AND "active" IS TRUE LIMIT 1;
        IF FOUND THEN
            RAISE EXCEPTION 'DUPLICATE_CODE: an active TriggerRow with this code already exists' USING ERRCODE = '45000';
        END IF;
    END IF;
    RETURN NEW;
END;
$ddd$;

DROP TRIGGER IF EXISTS "onTriggerRowInsert" ON "TriggerRows";

CREATE TRIGGER "onTriggerRowInsert"
    BEFORE INSERT ON "TriggerRows"
    FOR EACH ROW
    EXECUTE FUNCTION ddd_trigger_triggerrows_before_insert();
