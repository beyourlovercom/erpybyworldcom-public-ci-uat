<?php
/**
 * Synthetic demonstration data only.
 * All names, emails, addresses, order identifiers and tracking codes below
 * are fictitious and must not be used for actual fulfilment.
 */
$statuses = ['待发货', '已发待单号', '运输中', '已收货', '缺货', '信息缺失', '补发', '无需发货'];
$orders = [];
foreach ($statuses as $index => $status) {
    $n = $index + 1;
    $orders[] = [
        'order_number' => sprintf('DEMO-ORDER-%04d', $n),
        'created_at' => '2026-01-01 10:00',
        'shipping_status' => $status,
        'owner' => 'Demo Operator',
        'customer' => sprintf('Demo Customer %d', $n),
        'country' => 'United States',
        'sku' => 'DEMO-SKU-001',
        'quantity' => 1,
        'warehouse' => 'Demo Warehouse',
        'tracking_number' => sprintf('DEMO-TRACK-%04d', $n),
    ];
}
return [
    'orders' => $orders,
    'detail' => [
        'order_number' => 'DEMO-ORDER-0001',
        'shipping_status' => '待发货',
        'warehouse' => 'Demo Warehouse',
        'owner' => 'Demo Operator',
        'customer' => [
            'name' => 'Demo Customer 1',
            'phone' => '0000000000',
            'email' => 'demo@example.invalid',
            'country' => 'United States',
            'state' => 'Example State',
            'city' => 'Example City',
            'address' => '123 Example Street',
            'postcode' => '00000',
            'website' => 'https://example.invalid',
            'website_order' => 'DEMO-WEB-0001',
            'created_at' => '2026-01-01 10:00',
            'synced_at' => '2026-01-01 10:05',
            'product' => 'DEMO-SKU-001',
            'quantity' => 1,
        ],
        'structured_address' => [
            'Full name' => 'Demo Customer 1',
            'Address Street' => '123 Example Street',
            'City' => 'Example City',
            'State' => 'Example State',
            'Zipcode' => '00000',
            'Phone' => '0000000000',
            'Contact Email' => 'demo@example.invalid',
            'Country' => 'United States',
            'Notes' => 'SYNTHETIC DATA - DO NOT SHIP',
        ],
        'carrier' => 'Demo Carrier',
        'tracking_number' => 'DEMO-TRACK-0001',
        'quantity' => 1,
        'timeline' => [
            ['actor' => 'Demo Operator', 'action' => 'Created synthetic order', 'time' => '10:00'],
            ['actor' => 'Demo System', 'action' => 'Validated synthetic address', 'time' => '10:01'],
            ['actor' => 'Demo Operator', 'action' => 'Reviewed synthetic order', 'time' => '10:02'],
            ['actor' => 'Demo System', 'action' => 'Recorded demonstration status', 'time' => '10:03'],
            ['actor' => 'Demo Operator', 'action' => 'Verified synthetic warehouse', 'time' => '10:04'],
            ['actor' => 'Demo System', 'action' => 'Synced demonstration state', 'time' => '10:05'],
            ['actor' => 'Demo Operator', 'action' => 'Completed demo event', 'time' => '10:06'],
        ],
    ],
];
