ALTER TABLE trip_tickets
ADD COLUMN map_latitude DECIMAL(10,7) NULL AFTER google_maps_link,
ADD COLUMN map_longitude DECIMAL(10,7) NULL AFTER map_latitude;
