UPDATE {TABLE} SET lockout_until = DATE_ADD(NOW(), INTERVAL :seconds SECOND) WHERE id = :id
