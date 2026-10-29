UPDATE {TABLE} SET used_at = NOW() WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
