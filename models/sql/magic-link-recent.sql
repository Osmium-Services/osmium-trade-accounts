SELECT id FROM {TABLE} WHERE customer_id = :customer_id AND created_at > DATE_SUB(NOW(), INTERVAL :window_seconds SECOND) LIMIT 1
