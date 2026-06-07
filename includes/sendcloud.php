<?php
// ============================================================
// SENDCLOUD API HELPER
// Methods from v2 (weights are in KG not grams)
// Shipments created via v3 using shipping_option_code
// ============================================================

function sendcloudRequest(
    $endpoint,
    $method = 'GET',
    $data = null,
    $version = 'v2'
) {
    $baseUrl = 'https://panel.sendcloud.sc/api/'
        . $version . '/';
    $url     = $baseUrl . $endpoint;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => SENDCLOUD_PUBLIC_KEY
            . ':' . SENDCLOUD_SECRET_KEY,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => 'cURL error: ' . $error];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = 'API error ' . $httpCode;
        if (isset($decoded['error']['message'])) {
            $msg = $decoded['error']['message'];
        } elseif (isset($decoded['errors'][0]['detail'])) {
            $msg = $decoded['errors'][0]['detail'];
        }
        return [
            'error'     => $msg,
            'http_code' => $httpCode,
            'raw'       => $decoded,
        ];
    }

    return $decoded ?? [];
}

// ── Get shipping methods via v2 ──────────────────────────────
// IMPORTANT: v2 max_weight is in KG not grams
// We also fetch product codes from shipping-products endpoint
function getShippingMethods($weightGrams = 500)
{

    $weightKg = number_format($weightGrams / 1000, 3);

    // Use fetch-shipping-options to get REAL option codes
    $data = [
        'from_country_code' => 'GB',
        'to_country_code'   => 'GB',
        'weight' => [
            'value' => $weightKg,
            'unit'  => 'kg',
        ],
    ];

    $result = sendcloudRequest(
        'fetch-shipping-options',
        'POST',
        $data,
        'v3'
    );

    if (isset($result['error'])) {
        return ['error' => $result['error']];
    }

    $options  = $result['data'] ?? [];
    $filtered = [];

    foreach ($options as $opt) {
        $code        = $opt['code'] ?? '';
        $name        = $opt['name'] ?? '';
        $carrierCode = $opt['carrier']['code'] ?? '';
        $carrierName = $opt['carrier']['name'] ?? '';
        $nameLower   = strtolower($name);

        // Only Royal Mail and Evri for now
        $allowedCarriers = [
            'royal_mailv2',
            'hermes_c2c_gb'
        ];
        if (!in_array($carrierCode, $allowedCarriers)) continue;

        // Skip locker and parcelshop services
        $skipKeywords = [
            'locker',
            'parcelshop',
            'postable',
        ];
        $skip = false;
        foreach ($skipKeywords as $kw) {
            if (stripos($nameLower, $kw) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        // Clean carrier name
        $displayCarrier = $carrierCode === 'royal_mailv2'
            ? 'Royal Mail' : 'Evri';

        $filtered[] = [
            'id'           => $code, // use code as ID
            'option_code'  => $code,
            'name'         => $name,
            'carrier'      => $carrierCode,
            'carrier_name' => $displayCarrier,
            'max_weight_kg' => 20,
            'price'        => null,
        ];
    }

    return $filtered;
}

// ── Create shipment via v3 ───────────────────────────────────
// Uses shipping_option_code as required by v3
function createShipment($order, $parcel, $optionCode)
{

    $addressParts = explode(
        ',',
        $order['customer_address']
    );
    $street   = trim($addressParts[0] ?? '');
    $city     = trim($addressParts[1] ?? '');
    $postcode = trim($addressParts[2] ?? '');

    $weightKg = round(
        floatval($parcel['weight']) / 1000,
        3
    );

    $data = [
        'to_address' => [
            'name'           => $order['customer_name'],
            'address_line_1' => $street,
            'city'           => $city,
            'postal_code'    => $postcode,
            'country_code'   => 'GB',
            'phone_number'   => $order['customer_phone'] ?? '',
            'email'          => $order['customer_email'],
        ],
        'from_address' => [
            'sender_address_id' =>
            intval(SENDCLOUD_SENDER_ID),
        ],
        'ship_with' => [
            'type'       => 'shipping_option_code',
            'properties' => [
                'shipping_option_code' => $optionCode,
            ],
        ],
        'parcels' => [[
            'weight' => [
                'value' => $weightKg,
                'unit'  => 'kg',
            ],
            'dimensions' => [
                'length' => (string)floatval($parcel['length']),
                'width'  => (string)floatval($parcel['width']),
                'height' => (string)floatval($parcel['height']),
                'unit'   => 'cm',
            ],
        ]],
        'order_number'  => (string)$order['id'],
        'request_label' => true,
    ];

    return sendcloudRequest(
        'shipments',
        'POST',
        $data,
        'v3'
    );
}

// ── Extract label URL from response ─────────────────────────
function getLabelUrl($data)
{
    // v3 response
    if (isset($data['parcels'][0]['label'])) {
        return $data['parcels'][0]['label']['label_printer']
            ?? $data['parcels'][0]['label_url']
            ?? '';
    }
    // v2 response
    return $data['label']['label_printer']
        ?? $data['label']['normal_printer'][0]
        ?? '';
}



// ── Create Return Label via v3 Returns API ──────────────────
// ── Get return-capable shipping methods ─────────────────────
// Tries to fetch return options from the API first.
// Falls back to hardcoded known UK return option codes so the
// dropdown is NEVER empty.
function getReturnShippingMethods()
{
    // Try fetching return-capable options from API
    $data = [
        'from_country_code' => 'GB',
        'to_country_code'   => 'GB',
        'weight' => ['value' => 1.0, 'unit' => 'kg'],
    ];
 
    $result = sendcloudRequest(
        'fetch-shipping-options',
        'POST',
        $data,
        'v3'
    );
 
    $apiOptions = [];
    if (!isset($result['error'])) {
        $options = $result['data'] ?? [];
        foreach ($options as $opt) {
            $code        = $opt['code'] ?? '';
            $name        = $opt['name'] ?? '';
            $carrierCode = $opt['carrier']['code'] ?? '';
            $nameLower   = strtolower($name);
 
            // Only Royal Mail and Evri
            $allowedCarriers = ['royal_mailv2', 'hermes_c2c_gb'];
            if (!in_array($carrierCode, $allowedCarriers)) continue;
 
            // Only include return-specific services
            if (stripos($nameLower, 'return') === false
                && stripos($nameLower, 'tracked') === false) continue;
 
            $displayCarrier = $carrierCode === 'royal_mailv2'
                ? 'Royal Mail' : 'Evri';
 
            $apiOptions[] = [
                'option_code'  => $code,
                'name'         => $name,
                'carrier'      => $carrierCode,
                'carrier_name' => $displayCarrier,
            ];
        }
    }
 
    // Always return hardcoded fallbacks if API gives nothing useful.
    // These are the standard UK return option codes for Royal Mail
    // and Evri on Sendcloud — confirmed from their returns API docs.
    if (empty($apiOptions)) {
        $apiOptions = [
            [
                'option_code'  => 'royal-mail-tracked-returns-48',
                'name'         => 'Royal Mail Tracked Returns 48',
                'carrier'      => 'royal_mailv2',
                'carrier_name' => 'Royal Mail',
            ],
            [
                'option_code'  => 'royal-mail-tracked-returns-24',
                'name'         => 'Royal Mail Tracked Returns 24',
                'carrier'      => 'royal_mailv2',
                'carrier_name' => 'Royal Mail',
            ],
            [
                'option_code'  => 'hermes-c2c-gb:return/return',
                'name'         => 'Evri Return',
                'carrier'      => 'hermes_c2c_gb',
                'carrier_name' => 'Evri',
            ],
        ];
    }
 
    return $apiOptions;
}

// ── Create Return Label via v3 Returns API ──────────────────
// Endpoint: POST /api/v3/returns
// Docs: https://sendcloud.dev/api/v3/returns/create-a-return
// Response: { "return_id": 12345, "parcel_id": 67880 }
// Then fetch label: GET /api/v3/parcels/{parcel_id}/documents/label
function createReturnLabel($order, $optionCode)
{
    $addressParts = explode(',', $order['customer_address']);
    $street   = trim($addressParts[0] ?? '');
    $city     = trim($addressParts[1] ?? '');
    $postcode = trim($addressParts[2] ?? '');
 
    $data = [
        // Customer sends the parcel back — they are the sender
        'from_address' => [
            'name'           => $order['customer_name'],
            'address_line_1' => $street,
            'city'           => $city,
            'postal_code'    => $postcode,
            'country_code'   => 'GB',
            'phone_number'   => $order['customer_phone'] ?? '',
            'email'          => $order['customer_email'],
        ],
        // Your shop is the recipient
        'to_address' => [
            'name'           => SHOP_NAME,
            'address_line_1' => SHOP_ADDRESS,
            'city'           => SHOP_CITY,
            'postal_code'    => SHOP_POSTCODE,
            'country_code'   => 'GB',
            'email'          => SHOP_EMAIL,
        ],
        // NOTE: shipping_option_code is DIRECTLY inside ship_with
        // NOT nested under 'properties' (that's only for /shipments)
        'ship_with' => [
            'type'                 => 'shipping_option_code',
            'shipping_option_code' => $optionCode,
        ],
        'weight' => [
            'value' => 1.0,
            'unit'  => 'kg',
        ],
        'dimensions' => [
            'length' => 20,
            'width'  => 15,
            'height' => 10,
            'unit'   => 'cm',
        ],
        'collo_count'  => 1,
        'order_number' => 'RET-' . (string)$order['id'],
        'parcel_items' => [[
            'description' => 'Return item',
            'quantity'    => 1,
            'weight'      => ['value' => 1.0, 'unit' => 'kg'],
            'value'       => ['value' => 0.01, 'currency' => 'GBP'],
        ]],
    ];
 
    /* Debug log
    file_put_contents(
        __DIR__ . '/../sendcloud_return_debug.json',
        json_encode([
            'endpoint' => 'POST api/v3/returns',
            'payload'  => $data,
            'time'     => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT)
    );*/
 
    // Step 1: Create the return — response: { return_id, parcel_id }
    $result = sendcloudRequest('returns', 'POST', $data, 'v3');
 
    if (isset($result['error'])) {
        return $result;
    }
 
    // Step 2: Fetch the label PDF URL using the parcel_id
    $parcelId = $result['parcel_id'] ?? null;
    if (!$parcelId) {
        return ['error' => 'Return created but no parcel_id returned', 'raw' => $result];
    }
 
    // GET /api/v3/parcels/{parcel_id}/documents/label
    $labelResult = sendcloudRequest(
        'parcels/' . $parcelId . '/documents/label',
        'GET',
        null,
        'v3'
    );
 
    // Attach label info to result
    $result['label_url'] = $labelResult['data'][0]['link'] 
        ?? $labelResult['link'] 
        ?? '';
    $result['parcel_label'] = $labelResult;
 
    return $result;
}
