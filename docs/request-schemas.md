# Request body schemas

Strict validation lives in `spams/app/helpers/request_schema.php`
(`request_schema_enforce()`), called as the first statement of a route's POST block, before `csrf_verify()`.

Rules applied to every enforced route:

- Any field not declared in the route's schema is rejected (HTTP 422, field names logged, values never logged).
- `_csrf` is the only meta key allowed implicitly.
- Fields in the global forbidden list are rejected even if a route schema accidentally declares them.
- Types are checked: `string` (max/min/pattern), `int`, `float`, `bool`, `date` (`Y-m-d`), `enum`, `list`, `rows`.
- Arrays are rejected where a scalar is expected.
- Enforcement only validates. Handlers still read `$_POST`, so values are not altered.

## Fields a user must never set

The server derives these. They are rejected on every enforced route and must never be accepted from a request.

| Field(s) | Source of truth |
|---|---|
| `user_id`, `created_by`, `updated_by`, `deleted_by`, `sender_user_id`, `actor_id` | `current_user_id()` |
| `role`, `role_name`, `is_admin`, `permissions` | Set only by an admin through `role_id` on the users form |
| `password_hash`, `force_password_change`, `failed_attempts`, `locked_until`, `last_login_at` | Auth code |
| `created_at`, `updated_at`, `deleted_at` | Database |
| `status`, `is_distributed` | Workflow code (posting, receiving, cancelling) |
| `total_amount`, `grand_total` | Computed from line items |
| `qr_token` | Generated server-side |
| `property_number` | `generate_property_number()` (the PAR/ICS correction form edits an existing one, which is a separate audited path) |
| Classification (Equipment / Semi-expendable HV / LV) and depreciation outputs | `property_thresholds` and `calculate_accumulated_depreciation()` |

## Routes with strict schemas in code

| Route | Fields allowed (per action) |
|---|---|
| `auth/login.php` | username, password |
| `auth/change_password.php` | current_password, new_password, confirm_password |
| `auth/forgot_password.php` | identifier |
| `auth/reset_password.php` | token, new_password, confirm_password |
| `settings/change_password.php` | current_password, new_password, confirm_password |
| `settings/profile.php` | full_name, email, remove_photo |
| `settings/system_access.php` | local_access_url, tailscale_serve_url, session_timeout_minutes |
| `settings/thresholds.php` | equipment_min, semi_hv_min, effective_date, basis |
| `settings/thresholds_edit.php` | id, equipment_min, semi_hv_min, effective_date |
| `settings/thresholds_delete.php` | id |
| `settings/ris_approver.php` | ris_approved_by |
| `settings/university_president.php` | name, title, appointment date |
| `settings/upload_storage.php` | form_action, upload root and photo root paths, copy/move flags |
| `settings/purchase_order_notifications.php` | `save_settings`, `send_overdue_sms`, `send_email_test` (each with its own fields) |
| `settings/assignment_cleanup.php` | `set_primary`, `set_unit_head`, `deactivate_assignment`, `sync_office_head` (assignment_id, employee_id, office_id) |
| `settings/database_tools.php` | `create_backup`, `upload_restore`, `save_auto_backup_settings`, `run_auto_backup_test`, `validate_auto_backup_path` |
| `users/index.php` | `save`, `ensure_employee_role`, `delete`, `reactivate`, `hard_delete`, `reset_password` (the admin may set `role_id`; never role name or flags) |
| `messages/send.php` (JSON) | recipient_user_id, channel_key, subject, message_body, related_table, related_id |
| `messages/delete.php` (JSON) | message_id, channel_message_id |
| `trip_tickets/create.php` | `save` (ticket fields, passengers as rows), `delete` (id) |
| `trip_tickets/complete.php` | id plus completion fields |
| `trip_tickets/vehicles.php` | `save` |
| `trip_tickets/fuel_ris.php`, `fuel_ris_create.php` | `add_station` (station_name_new); `save` (id, ris_date, ris_no, gas_station_id, station_name, vehicle_id, driver_name, fuel_type, unit, quantity, amount, odometer_reading, purpose, remarks); `delete` (id); `import` (import_quantity_mode, plus the `import_file` upload) |
| `disposals/index.php` | source_type (system/legacy), return_id, return_ids[], legacy_asset_id, disposal_date, reason, approved_by, remarks |
| `distributions/index.php` | `post_distribution` (document_type, semi_type, receiving_id, office_id, employee_id, distribution_date, purpose, remarks, units[id], unit_remarks[id]); `update_distribution` (edit_id, office_id, employee_id, distribution_date, purpose, remarks, quantity_distributed[id], line_remarks[id], detail_property_number[id], detail_brand[id], detail_model[id], detail_serial_no[id], detail_remarks[id]) |
| `distributions/view.php` | `cancel_distribution` (cancel_reason); `correct_distribution_unit` (detail_id, next_status, reason, remarks) |

## Remaining write routes (not yet enforced)

The keys below were extracted from the code. Where a route also reads fields through a helper such as `old($_POST, ...)`, the
extraction can miss some, so confirm against the handler before turning on enforcement. These routes still rely on CSRF, role
checks and per-route validation. This inventory is not a strict schema and does not satisfy all-route coverage yet.

| Route | Fields read |
|---|---|
| `account_codes`, `classifications`, `departments`, `funds`, `mode_of_procurements`, `responsibility_codes`, `stock_catalog`, `suppliers`, `unit_of_measures`, `offices` | action, id, is_active, plus master-data columns (offices also target_office_id) |
| `brands` | action, id, brand_id, model_name, description, is_active |
| `models` | action, id, brand_name, description, is_active |
| `locations` | action, id, location_name, description, is_active |
| `employees` | action, id, assignments, assignment_primary_index, existing_photo_path, is_active, is_driver, is_unit_head, remove_photo, source_employee_id, target_employee_id (+ profile columns) |
| `issuances` | po_id, items |
| `maintenance` | action, id, inventory_status_target |
| `property/encode_legacy_asset` | action, bulk_* fields |
| `property/inventory_counts` | action, count_date, count_type, description, expected_updated_at, item_id, item_ids, location_id, location_name, notes, office_id, remarks, scan_value, session_id, status |
| `property/inventory_reconciliation` | action, item_id, resolution_action, resolution_notes, resolution_status, session_id |
| `property/legacy_assets` | action, asset_id, is_rpcppe_candidate, rpcppe_status |
| `property/legacy_assets_quickadd` | action, account/brand/model/classification/office/employee/supplier quick-add fields |
| `property/legacy_import` | action (+ file upload) |
| `property/par_ics_reconciliation` | action, employee and responsibility-code fields, hardcopy_*, item_id, notes, resolution |
| `property/rpcppe_selection` | action, asset_keys, rpcppe_status |
| `property/scan` | action, condition_status, description, employee_id, location_id, location_name, mobile_note, office_id |
| `property/stock_adjustments` | action, adjustment_date, adjustment_id, cancel_reason, items, remarks, session_id |
| `property/supply_counts` | action, count_date, count_type, counted_quantity, item_id, notes, remarks, scan_increment, scan_value, session_id |
| `property/unserviceable_review` | action, item_id, resolution_action, resolution_notes |
| `property/view` | action and the item edit fields (item_description, item_type, brand, model, serial_no, unit_cost, quantity, ids, condition_status, remarks, photo_id, **property_number**) |
| `purchase_orders/create`, `edit`, `index`, `extensions` | action, items (rows), is_partial_entry, cancel_id, cancel_reason, header fields |
| `purchase_orders/catalog_quick_add`, `classification_quick_add`, `po_masterdata_quickadd`, `demand_letter` | quick-add fields, action, sent_date |
| `receivings/index` | action, purchase_order_id, receiving_id, items, cancel_reason, confirm_physical_receipt(+_checked) |
| `receivings/correct_receiving` | action, receiving_id, receiving_item_id |
| `reports/rpcppe_batches` | action, as_of_date, batch_id, batch_name, batch_year, excel_path, include_value, is_included, item_id, notes, reconciliation_status, source_batch_id |
| `returns/index` | action, disposition_status, distribution_item_detail_id(s), legacy_asset_id(s), reason, remarks, return_date, return_id, source_type |
| `transfers/index`, `transfers/view` | action, asset_keys, mode |

Highest-value next candidates: `property/view` (it takes `property_number`, a never-set field), `purchase_orders/*`,
`receivings/index`, `returns/index`, `employees/index` and `transfers/*`.
