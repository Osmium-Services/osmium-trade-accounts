UPDATE {TABLE} SET used = 1 WHERE customer_id = :customer_id AND code = :code AND used = 0 AND expires_at > NOW()
