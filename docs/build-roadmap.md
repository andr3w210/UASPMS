# SPAMS Build Roadmap

This project is easiest to build if each module introduces its own tables, validation rules, and UI in small slices.

## Suggested implementation order

1. Foundation
   - Authentication
   - Roles and permissions
   - Audit logs
   - Series numbers
2. Master data
   - Departments
   - Employees
   - Suppliers
   - Categories
   - Units of measure
   - Locations and offices
3. Item catalog
   - Supply items
   - Asset or property items
   - Brand and model references
   - Useful life and thresholds
4. Procurement and receiving
   - Purchase orders
   - Receiving records
   - Stock entries
5. Inventory movement
   - Issuances
   - Returns
   - Stock cards
6. Property accountability
   - ICS
   - PAR
   - Property assignments
   - Transfer, relief, lost, damaged, disposal
7. Reports and year-end processes
   - Inventory reports
   - Property ledgers
   - Closing and archival summaries

## Module-to-table approach

Each module should answer these questions before coding starts:

1. What is the main record?
   - Example: `suppliers`, `issuances`, `property_assignments`
2. What child records are needed?
   - Example: `issuance_items`, `receiving_items`
3. What lookup tables does it depend on?
   - Example: `categories`, `departments`, `units_of_measure`
4. What status flow does it use?
   - Example: `draft`, `posted`, `cancelled`, `returned`
5. What audit trail is required?
   - Creator, updater, timestamps, optional approval history

## Minimum table standards

Use these fields consistently unless a table has a strong reason not to:

- `id` bigint unsigned primary key auto increment
- `created_at` datetime not null default current timestamp
- `updated_at` datetime null default null on update current timestamp
- `created_by` bigint unsigned null
- `updated_by` bigint unsigned null
- `is_active` tinyint(1) not null default 1 for master data tables

## Naming conventions

- Use plural snake_case table names: `suppliers`, `stock_receipts`
- Use singular foreign key names ending in `_id`: `supplier_id`, `employee_id`
- Use separate header and detail tables for transactions:
  - `issuances`
  - `issuance_items`
- Use lookup tables for values that admins may change later

## Recommended next coding target

Build the next modules in this order:

1. `users` and `roles`
2. `departments`
3. `employees`
4. `suppliers`
5. `categories`
6. `items`

That sequence unlocks most later transaction modules without rework.
