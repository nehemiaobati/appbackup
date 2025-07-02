<?php

// --- Constants ---
define('BINANCE_FUTURES_PROD_REST_API_BASE_URL', 'https://fapi.binance.com');
define('BINANCE_FUTURES_TEST_REST_API_BASE_URL', 'https://testnet.binancefuture.com');
define('BINANCE_FUTURES_PROD_WS_BASE_URL', 'wss://fstream.binance.com');
define('BINANCE_FUTURES_TEST_WS_BASE_URL_COMBINED', 'wss://stream.binancefuture.com');

// Binance API base URL (using testnet as requested)
define('BINANCE_API_BASE_URL', BINANCE_FUTURES_TEST_REST_API_BASE_URL . '/fapi/v1/klines');

// Instrument ID (e.g., BTCUSDT for Binance)
define('INSTRUMENT_ID', 'BTCUSDT');

// Timeframes to fetch (Binance uses '1m', '3m', '5m', '15m', '30m' for intervals)
$timeframes = [
    '1m' => '1m',
    '3m' => '3m',
    '5m' => '5m',
    '15m' => '15m',
    '30m' => '30m',
];

/**
 * Fetches KLine data from Binance API.
 *
 * @param string $symbol The trading pair symbol (e.g., BTCUSDT).
 * @param string $interval The candlestick interval (e.g., 1m, 5m, 1h, 1d).
 * @param int $limit The number of candles to retrieve.
 * @return array|null KLine data or null on failure.
 */
function fetchKlineData($symbol, $interval, $limit = 100) {
    $url = BINANCE_API_BASE_URL . "?symbol=" . urlencode($symbol) . "&interval=" . urlencode($interval) . "&limit=" . $limit;

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

    // Binance KLine data is a direct array of arrays, no 'data' key
    if (!is_array($data)) {
        echo "Invalid API response format: expected an array.\n";
        return null;
    }

    return $data;
}

echo "Starting KLine data fetching for " . INSTRUMENT_ID . "...\n";

while (true) {
    echo "\nFetching data at " . date('Y-m-d H:i:s') . "...\n";

    foreach ($timeframes as $name => $interval) {
        echo "--- Timeframe: " . $name . " ---\n";
        $limit = 100; // Define the limit for clarity
        $klineData = fetchKlineData(INSTRUMENT_ID, $interval, $limit);

        if ($klineData) {
            $count = count($klineData);
            echo "Total KLines received: " . $count . "\n";

            if ($count > 0) {
                echo "Last 5 KLines:\n";
                // Display the last 5 KLines
                $last5 = array_slice($klineData, -5);
                foreach ($last5 as $kline) {
                    // Binance KLine format:
                    // [
                    //   1499040000000,      // Open time
                    //   "0.01634790",       // Open
                    //   "0.80000000",       // High
                    //   "0.01575800",       // Low
                    //   "0.01577100",       // Close
                    //   "148976.10704623",  // Volume
                    //   1499644799999,      // Close time
                    //   "2434.19055334",    // Quote asset volume
                    //   232,                // Number of trades
                    //   "1000.00000000",    // Taker buy base asset volume
                    //   "1000.00000000",    // Taker buy quote asset volume
                    //   "0"                 // Ignore.
                    // ]
                    $timestamp = date('Y-m-d H:i:s', $kline[0] / 1000); // Convert ms to s
                    echo "  Timestamp: " . $timestamp . ", Open: " . $kline[1] . ", Close: " . $kline[4] . ", High: " . $kline[2] . ", Low: " . $kline[3] . ", Volume: " . $kline[5] . "\n";
                }
            } else {
                echo "No KLines received for this timeframe.\n";
            }

            // If less data than requested is received, break the loop
            if ($count < $limit) {
                echo "Received less than " . $limit . " KLines. Ending loop.\n";
                break;
            }

        } else {
            echo "Failed to fetch KLine data for " . $name . ".\n";
        }
    }

    echo "\nWaiting 60 seconds...\n";
    sleep(60); // Wait for 60 seconds
}

?>
