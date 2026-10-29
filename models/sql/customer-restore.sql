UPDATE {TABLE} SET deleted_at = NULL WHERE id = :id AND deleted_at IS NOT NULL
