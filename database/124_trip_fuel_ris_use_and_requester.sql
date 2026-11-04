-- Fuel RIS can be for a vehicle, equipment (e.g. powerplant/generator) or other use,
-- and the requester is usually the unit head rather than the driver.
ALTER TABLE trip_fuel_ris_entries
    ADD COLUMN fuel_use VARCHAR(20) NOT NULL DEFAULT 'vehicle' AFTER vehicle_name,
    ADD COLUMN use_description VARCHAR(150) NULL AFTER fuel_use,
    ADD COLUMN requested_by VARCHAR(200) NULL AFTER driver_name;
