-- Trade customers (not deleted) by status, with how long the oldest pending application has waited, how many joined
-- recently, how many active customers logged in this month or never, and how many are locked out right now.
-- Counts and dates only: no name, company, email or address.
SELECT
    status,
    COUNT(*) AS customers,
    TIMESTAMPDIFF(DAY, MIN(created_at), NOW()) AS oldest_days,
    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS joined_7_days,
    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS joined_30_days,
    SUM(last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS logged_in_30_days,
    SUM(last_login IS NULL) AS never_logged_in,
    SUM(lockout_until IS NOT NULL AND lockout_until > NOW()) AS locked_out
FROM {TABLE}
WHERE deleted_at IS NULL
GROUP BY status
