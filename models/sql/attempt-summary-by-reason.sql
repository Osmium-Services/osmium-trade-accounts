-- Login attempts in the last 7 days by outcome. No email, customer, address or browser.
SELECT reason, COUNT(*) AS attempts
FROM {TABLE}
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY reason
ORDER BY attempts DESC
