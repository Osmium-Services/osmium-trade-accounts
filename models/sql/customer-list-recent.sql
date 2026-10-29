SELECT C.id, C.company_name, C.status, C.created_at, C.updated_at, C.deleted_at, CL.user_name AS changed_by, CU.avatar AS changed_by_avatar
FROM {TABLE} AS C
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'trade_customer' AND record_id = C.id)
LEFT JOIN {PREFIX}users AS CU ON CU.id = CL.user_id
WHERE (C.created_at >= NOW() - INTERVAL 24 HOUR OR C.updated_at >= NOW() - INTERVAL 24 HOUR OR C.deleted_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(C.updated_at, C.created_at), C.created_at, COALESCE(C.deleted_at, C.created_at)) DESC, C.id DESC
LIMIT 10
