SELECT * FROM {TABLE} WHERE deleted_at IS NULL AND FIND_IN_SET(status, :statuses) > 0 ORDER BY company_name ASC
