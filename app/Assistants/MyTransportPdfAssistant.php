<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Assistants\PdfClient;
use App\GeonamesCountry;

class MyTransportPdfAssistant extends PdfClient
{
    public function processLines(array $lines)
    {
        $data = [
            'attachment_filenames' => [],
            'customer' => [
                'side' => 'sender',
                'details' => [
                    'company' => null,
                    'company_code' => null,
                    'vat_code' => null,
                    'email' => null,
                    'contact_person' => null,
                    'street_address' => null,
                    'title' => null,
                    'city' => null,
                    'country' => null,
                    'postal_code' => null,
                    'comment' => null,
                ],
            ],
            'loading_locations' => [],
            'destination_locations' => [],
            'cargos' => [],
            'container' => [
                'container_number' => null,
                'container_type' => null,
                'booking_reference' => null,
                'shipping_line' => null,
            ],
            'order_reference' => null,
            'freight_price' => null,
            'freight_currency' => null,
            'transport_numbers' => null,
            'comment' => null,
            'incoterms' => null,
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            // Order reference
            if (Str::startsWith($line, 'Order No')) {
                $data['order_reference'] = trim(Str::after($line, ':'));
            }

            // Customer details
            if (Str::startsWith($line, 'Customer')) {
                $data['customer']['details']['company'] = trim(Str::after($line, ':'));
            }

            if (Str::startsWith($line, 'Destination')) {
                $destination = trim(Str::after($line, ':'));
                $data['destination_locations'][] = [
                    'company_address' => [
                        'company' => $destination,
                        'country' => GeonamesCountry::getIso($destination)
                    ],
                    'time' => [
                        'datetime_from' => Carbon::now()->toIso8601String()
                    ]
                ];
            }

            if (Str::startsWith($line, 'Loading')) {
                $loading = trim(Str::after($line, ':'));
                $data['loading_locations'][] = [
                    'company_address' => [
                        'company' => $loading,
                        'country' => GeonamesCountry::getIso($loading)
                    ],
                    'time' => [
                        'datetime_from' => Carbon::now()->toIso8601String()
                    ]
                ];
            }

            // Cargos example
            if (preg_match('/^Item\s+(\w+)\s+Qty\s+([0-9]+)/i', $line, $matches)) {
                $data['cargos'][] = [
                    'title' => $matches[1],
                    'package_count' => (int) $matches[2],
                    'package_type' => 'EPAL',
                    'number' => null,
                    'type' => 'full',
                    'value' => null,
                    'currency' => 'EUR',
                    'pkg_width' => null,
                    'pkg_length' => null,
                    'pkg_height' => null,
                    'ldm' => null,
                    'volume' => null,
                    'weight' => null,
                    'chargeable_weight' => null,
                    'temperature_min' => null,
                    'temperature_max' => null,
                    'temperature_mode' => null,
                    'adr' => false,
                    'extra_lift' => false,
                    'palletized' => false,
                    'manual_load' => false,
                    'vehicle_make' => null,
                    'vehicle_model' => null,
                ];
            }
        }

        return $this->createOrder($data);
    }
}
