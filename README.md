# php-trip-segmentation

A lightweight PHP 8 script for processing GPS point data from a CSV file, splitting it into separate trips based on time and distance rules, computing trip statistics, and exporting each trip as a colored GeoJSON `LineString`.

## Features
- **Data cleaning** – discards rows with invalid coordinates or timestamps (logs them to `rejects.log`).
- **Sorting** – orders points by timestamp.
- **Trip splitting** – starts a new trip when:
  - Time gap > **25 minutes**  
  - **OR** straight-line distance jump > **2 km** (Haversine formula)
- **Statistics per trip**:
  - Total distance (km)
  - Duration (min)
  - Average speed (km/h)
  - Maximum segment speed (km/h)
- **GeoJSON output** – each trip is a `LineString` with its own color and trip stats in `properties`.
- **No external dependencies** – pure PHP, no database or APIs.
- **Fast** – completes in under a minute for large CSVs on a typical laptop.

## Requirements
- PHP 8.0 or higher
- A CSV file with headers including:
  - `lat`, `latitude`, or `y`
  - `lon`, `lng`, `longitude`, or `x`
  - `timestamp`, `time`, `datetime`, `date`, `ts`, or `iso8601`
