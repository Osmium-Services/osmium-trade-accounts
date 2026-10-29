SELECT id FROM {TABLE} WHERE (email = :email OR email2 = :email) AND id <> :exclude_id AND deleted_at IS NULL LIMIT 1
