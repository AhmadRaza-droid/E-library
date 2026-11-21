-- Link each contact message to the registered user who sent it.
-- Run this migration once in phpMyAdmin.
ALTER TABLE messages
    ADD COLUMN user_id INT NULL AFTER id,
    ADD INDEX idx_messages_user_id (user_id);

-- Attach older messages to matching registered accounts where possible.
UPDATE messages AS m
INNER JOIN users AS u
    ON LOWER(TRIM(u.email)) = LOWER(TRIM(m.email))
SET m.user_id = u.id
WHERE m.user_id IS NULL;
