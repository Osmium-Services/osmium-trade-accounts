UPDATE {TABLE} SET
    email = :email, email2 = :email2, company_name = :company_name, contact_name = :contact_name,
    phone = :phone, address_line_1 = :address_line_1, address_line_2 = :address_line_2,
    city = :city, county = :county, postcode = :postcode, country = :country, notes = :notes
WHERE id = :id AND deleted_at IS NULL
