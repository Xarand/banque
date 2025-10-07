-- migrations/202510071345_add_exclude_from_ca.sql
ALTER TABLE transactions ADD COLUMN exclude_from_ca INTEGER NOT NULL DEFAULT 0;