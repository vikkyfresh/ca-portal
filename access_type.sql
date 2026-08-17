-- Run this once on your database
ALTER TABLE tests 
ADD COLUMN access_type ENUM('general','custom') NOT NULL DEFAULT 'general' 
AFTER is_draft;
