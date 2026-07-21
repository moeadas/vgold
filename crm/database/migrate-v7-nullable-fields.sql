-- Victory Genomics CRM — Migration V7: Make company_name and country nullable
-- Only the contact person's name is required when creating a lead.
-- All other fields (company_name, country, etc.) are optional and accept NULL.
-- Run this on the production database.

ALTER TABLE leads
    MODIFY COLUMN `company_name` varchar(200) DEFAULT NULL;

ALTER TABLE leads
    MODIFY COLUMN `country` varchar(100) DEFAULT NULL;
