INSERT INTO {TABLE} (customer_id, token_hash, ip_address, user_agent, expires_at) VALUES (:customer_id, :token_hash, :ip_address, :user_agent, DATE_ADD(NOW(), INTERVAL :expiry_minutes MINUTE))
