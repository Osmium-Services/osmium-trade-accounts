UPDATE {TABLE} SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL
