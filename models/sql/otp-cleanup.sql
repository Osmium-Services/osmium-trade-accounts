DELETE FROM {TABLE} WHERE expires_at < NOW() OR used = 1
