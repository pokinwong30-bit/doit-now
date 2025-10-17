-- Add position column to users table for storing employee job titles
ALTER TABLE users
    ADD COLUMN position VARCHAR(150) NOT NULL AFTER password_hash;
