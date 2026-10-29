SELECT C.id, C.company_name, C.logo_filename, C.deleted_at, CL.user_name AS changed_by, CU.avatar AS changed_by_avatar
FROM {TABLE} AS C
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'trade_customer' AND record_id = C.id)
LEFT JOIN {PREFIX}users AS CU ON CU.id = CL.user_id
WHERE C.deleted_at IS NOT NULL
ORDER BY C.deleted_at DESC
