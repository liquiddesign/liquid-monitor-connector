-- lqcron schéma verze 2 (kontrakt docs/v4-contract.md § 2.3): aditivní migrace nad v1,
-- `min_reader_version` zůstává 1 (PHP ani starší lqcron tyto sloupce nepotřebují).
-- Jen SQL kompatibilní se SQLite 3.24 (plán § 12.1).
ALTER TABLE runs ADD COLUMN pgid INTEGER;
ALTER TABLE runs ADD COLUMN pgid_start INTEGER;
ALTER TABLE runs ADD COLUMN boot_id TEXT;
ALTER TABLE commands ADD COLUMN ack_event_id TEXT;
