UPDATE {TABLE} SET source = :source, source_ref = :source_ref WHERE id = :id AND (source IS NULL OR source = '')
