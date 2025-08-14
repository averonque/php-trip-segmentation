<?php
declare(strict_types=1);

const TIME_GAP_MINUTES = 25;
const DIST_JUMP_KM     = 2.0;
const MIN_POINTS_FOR_LINESTRING = 2;

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
    return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function parse_time(string $raw): ?int {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/^\d{10}$/', $raw)) return (int)$raw;
    if (preg_match('/^\d{13}$/', $raw)) return (int) floor(((int)$raw) / 1000);
    try {
        return (new DateTimeImmutable($raw))->getTimestamp();
    } catch (Exception $e) { return null; }
}

function find_column(array $headers, array $candidates): ?int {
    foreach ($headers as $i => $h) {
        if (in_array(strtolower(trim($h)), $candidates, true)) return $i;
    }
    return null;
}

function palette_color(int $i): string {
    static $palette = [
        '#e41a1c','#377eb8','#4daf4a','#984ea3','#ff7f00',
        '#ffff33','#a65628','#f781bf','#999999','#66c2a5',
        '#fc8d62','#8da0cb','#e78ac3','#a6d854','#ffd92f',
        '#e5c494','#b3b3b3'
    ];
    return $palette[$i % count($palette)];
}

// --- Input ---
if ($argc < 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} points.csv\n");
    exit(1);
}

$csvPath = $argv[1];
if (!is_readable($csvPath)) {
    fwrite(STDERR, "ERROR: Cannot read $csvPath\n");
    exit(1);
}

// --- Output file name ---
$outPath = preg_replace('/\.csv$/i', '.geojson', $csvPath);
if ($outPath === $csvPath) $outPath .= '.geojson';

// --- Rejects log ---
$rejectLog = fopen('rejects.log', 'w') or exit("Cannot open rejects.log\n");

// --- Read CSV ---
$fh = new SplFileObject($csvPath, 'r');
$fh->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
$header = $fh->fgetcsv();

$latIdx  = find_column($header, ['lat','latitude','y']);
$lonIdx  = find_column($header, ['lon','lng','longitude','x']);
$timeIdx = find_column($header, ['timestamp','time','datetime','date','ts','iso8601']);

if ($latIdx === null || $lonIdx === null || $timeIdx === null) {
    fwrite(STDERR, "Missing lat/lon/time columns\n");
    exit(1);
}

$points = [];
$lineNo = 1;
while (!$fh->eof()) {
    $row = $fh->fgetcsv();
    if ($row === false || $row === [null] || $row === null) continue;
    $lineNo++;

    $latRaw = trim((string)($row[$latIdx] ?? ''));
    $lonRaw = trim((string)($row[$lonIdx] ?? ''));
    $timeRaw= trim((string)($row[$timeIdx] ?? ''));

    if ($latRaw === '' || $lonRaw === '' || $timeRaw === '' ||
        !is_numeric($latRaw) || !is_numeric($lonRaw)) {
        fwrite($rejectLog, "Line $lineNo: invalid/missing coords or time\n");
        continue;
    }
    $lat = (float)$latRaw; $lon = (float)$lonRaw;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        fwrite($rejectLog, "Line $lineNo: out-of-bounds coords\n");
        continue;
    }
    $ts = parse_time($timeRaw);
    if ($ts === null) {
        fwrite($rejectLog, "Line $lineNo: bad timestamp\n");
        continue;
    }
    $points[] = ['t'=>$ts,'lat'=>$lat,'lon'=>$lon,'i'=>$lineNo];
}
fclose($rejectLog);

// --- Sort ---
usort($points, fn($a,$b) => $a['t'] <=> $b['t'] ?: $a['i'] <=> $b['i']);
if (!$points) {
    file_put_contents($outPath, json_encode(['type'=>'FeatureCollection','features'=>[]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    exit;
}

// --- Split trips ---
$trips = [];
$current = [];
$prev = null;
foreach ($points as $p) {
    if ($prev === null) {
        $current = [$p];
    } else {
        $dtMin = ($p['t'] - $prev['t']) / 60.0;
        $dkm = haversine_km($prev['lat'],$prev['lon'],$p['lat'],$p['lon']);
        if ($dtMin > TIME_GAP_MINUTES || $dkm > DIST_JUMP_KM) {
            if ($current) $trips[] = $current;
            $current = [$p];
        } else {
            $current[] = $p;
        }
    }
    $prev = $p;
}
if ($current) $trips[] = $current;

// --- Build GeoJSON ---
$features = [];
$tripIndex = 0;
foreach ($trips as $trip) {
    if (count($trip) < MIN_POINTS_FOR_LINESTRING) continue;

    $coords = [];
    $totalKm = 0.0; $maxKmh = 0.0;
    for ($i = 0; $i < count($trip); $i++) {
        $coords[] = [$trip[$i]['lon'], $trip[$i]['lat']];
        if ($i > 0) {
            $segKm = haversine_km($trip[$i-1]['lat'],$trip[$i-1]['lon'],$trip[$i]['lat'],$trip[$i]['lon']);
            $totalKm += $segKm;
            $dh = max(0, ($trip[$i]['t'] - $trip[$i-1]['t']) / 3600.0);
            if ($dh > 0) $maxKmh = max($maxKmh, $segKm / $dh);
        }
    }
    $startT = $trip[0]['t']; $endT = end($trip)['t'];
    $durMin = ($endT - $startT) / 60.0;
    $avgKmh = $durMin > 0 ? $totalKm / ($durMin / 60.0) : 0;

    $tripId = 'trip_' . (++$tripIndex);
    $features[] = [
        'type'=>'Feature',
        'geometry'=>['type'=>'LineString','coordinates'=>$coords],
        'properties'=>[
            'trip_id'=>$tripId,
            'points_count'=>count($trip),
            'total_distance_km'=>round($totalKm,3),
            'duration_min'=>round($durMin,1),
            'avg_speed_kmh'=>round($avgKmh,2),
            'max_speed_kmh'=>round($maxKmh,2),
            'start_time_iso'=>gmdate('c',$startT),
            'end_time_iso'=>gmdate('c',$endT),
            'stroke'=>palette_color($tripIndex-1),
            'stroke-width'=>3
        ]
    ];
}

// --- Write GeoJSON to file ---
$geojson = json_encode(['type'=>'FeatureCollection','features'=>$features], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (file_put_contents($outPath, $geojson) === false) {
    fwrite(STDERR, "ERROR: Failed to write $outPath\n");
    exit(1);
}

fwrite(STDERR, "GeoJSON written to $outPath\n");
