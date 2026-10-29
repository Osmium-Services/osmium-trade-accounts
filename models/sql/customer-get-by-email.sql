SELECT * FROM {TABLE} WHERE (email = :email OR email2 = :email) AND deleted_at IS NULL LIMIT 1
