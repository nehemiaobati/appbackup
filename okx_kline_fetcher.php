<?php

// OKX API base URL
define('OKX_API_BASE_URL', 'https://www.okx.com/api/v5/market/candles');

// Instrument ID (e.g., BTC-USDT)
define('INSTRUMENT_ID', 'BTC-USDT');

// Timeframes to fetch (OKX uses 'min', 'min', 'min', 'hour', 'day' for intervals)
$timeframes = [
    '1m' => '1m',
    '5m' => '5m',
    '30m' => '30m',
    '1H' => '1H',
    '1D' => '1D',
];

/**
 * Fetches KLine data from OKX API.
 *
 * @param string $instId The instrument ID (e.g., BTC-USDT).
 * @param string $bar The candlestick interval (e.g., 1m, 5m, 1H, 1D).
 * @param int $limit The number of candles to retrieve.
 * @return array|null KLine data or null on failure.
 */
function fetchKlineData($instId, $bar, $limit = 100) {
    $url = OKX_API_BASE_URL . "?instId=" . urlencode($instId) . "&bar=" . urlencode($bar) . "&limit=" . $limit;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds timeout

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        echo "cURL Error: " . $error . "\n";
        return null;
    }

    if ($httpCode !== 200) {
        echo "HTTP Error: " . $httpCode . " - " . $response . "\n";
        return null;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Decode Error: " . json_last_error_msg() . "\n";
        return null;
    }

    if (!isset($data['data']) || !is_array($data['data'])) {
        echo "Invalid API response format: 'data' key missing or not an array.\n";
        return null;
    }

    return $data['data'];
}

echo "Starting KLine data fetching for " . INSTRUMENT_ID . "...\n";

while (true) {
    echo "\nFetching data at " . date('Y-m-d H:i:s') . "...\n";

    foreach ($timeframes as $name => $bar) {
        echo "--- Timeframe: " . $name . " ---\n";
        $klineData = fetchKlineData(INSTRUMENT_ID, $bar, 100); // Fetch up to 100 candles to ensure we have at least 5

        if ($klineData) {
            $count = count($klineData);
            echo "Total KLines received: " . $count . "\n";

            if ($count > 0) {
                echo "Last 5 KLines:\n";
                // Display the last 5 KLines
                $last5 = array_slice($klineData, -5);
                foreach ($last5 as $kline) {
                    // KLine format: [ts, open, high, low, close, vol, volCcy, volCcyQuote, confirm]
                    $timestamp = date('Y-m-d H:i:s', $kline[0] / 1000); // Convert ms to s
                    echo "  Timestamp: " . $timestamp . ", Open: " . $kline[1] . ", Close: " . $kline[4] . ", High: " . $kline[2] . ", Low: " . $kline[3] . ", Volume: " . $kline[5] . "\n";
                }
            } else {
                echo "No KLines received for this timeframe.\n";
            }
        } else {
            echo "Failed to fetch KLine data for " . $name . ".\n";
        }
    }

    echo "\nWaiting 60 seconds...\n";
    sleep(60); // Wait for 60 seconds
}

?>
