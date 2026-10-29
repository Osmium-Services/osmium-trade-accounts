INSERT INTO {TABLE} (customer_id, code, expires_at) VALUES (:customer_id, :code, DATE_ADD(NOW(), INTERVAL :expiry_minutes MINUTE))
