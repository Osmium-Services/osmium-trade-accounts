INSERT INTO {TABLE} (
    email, email2, company_name, contact_name, phone, address_line_1, address_line_2,
    city, county, postcode, country, status, source, source_ref, notes, created_by
) VALUES (
    :email, :email2, :company_name, :contact_name, :phone, :address_line_1, :address_line_2,
    :city, :county, :postcode, :country, :status, :source, :source_ref, :notes, :created_by
)
