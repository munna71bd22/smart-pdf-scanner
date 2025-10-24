<?php

namespace App\Assistants;

use App\Helpers\Helper;
use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

class OrderFromPdf extends PdfClient
{
    /**
     * Determine if this assistant should process the given PDF lines.
     */
    public static function validateFormat(array $lines): bool
    {
        $content = strtolower(implode(' ', $lines));
        // Identify PDFs by common words
        return Str::contains($content, ['order', 'loading', 'consignee', 'shipment']);
    }

    /**
     * Main processor method called from PdfClient::processPath().
     * Must call $this->createOrder($data).
     */
    public function processLines(array $lines, ?string $attachment_filename = null): array
    {
        // Normalize and clean lines
        $lines = array_values(array_filter(array_map('trim', $lines)));

        // Attachment filenames
        $attachment_filenames = $attachment_filename ? [$attachment_filename] : [];

        // Extract sender (shipper) and receiver (consignee)
        $sender = $this->extractCompany($lines, ['shipper', 'sender', 'customer']);
        $receiver = $this->extractCompany($lines, ['consignee', 'receiver', 'delivery']);

        // Loading and destination times
        $loading_from = $this->extractDate($lines, 'loading date') ?? Carbon::now()->toIso8601String();
        $delivery_from = $this->extractDate($lines, 'delivery date') ?? Carbon::now()->addDays(1)->toIso8601String();

        // Always provide a valid ISO string for datetime_to
        $loading_to = Carbon::parse($loading_from)->addHours(2)->toIso8601String();
        $delivery_to = Carbon::parse($delivery_from)->addHours(4)->toIso8601String();

        // Build loading and destination locations
        $loading_locations = [[
            'company_address' => $sender,
            'time' => [
                'datetime_from' => $loading_from,
                'datetime_to' => $loading_to,
            ],
        ]];

        $destination_locations = [[
            'company_address' => $receiver,
            'time' => [
                'datetime_from' => $delivery_from,
                'datetime_to' => $delivery_to,
            ],
        ]];

        // Extract cargo information
        $cargos = $this->extractCargos($lines);

        // Extract order reference
        $order_reference = $this->extractLineValue($lines, 'order ref|customer ref|our ref', true) ?? 'ORD-' . strtoupper(Str::random(6));

        // Assemble final structured order
        $data = [
            'attachment_filenames' => $attachment_filenames,
            'customer' => [
                'side' => 'sender',
                'details' => $this->normalizeCompany($sender),
            ],
            'loading_locations' => $loading_locations,
            'destination_locations' => $destination_locations,
            'cargos' => $cargos,
            'order_reference' => $order_reference,
            'freight_price' => 0.0,
            'freight_currency' => 'EUR',
            'transport_numbers' => $this->extractLineValue($lines, 'truck|vehicle|registration', false) ?? '',
            'comment' => $this->extractLineValue($lines, 'comment|note', false) ?? '',
            'incoterms' => $this->extractLineValue($lines, 'incoterms', false) ?? 'CFR',
        ];

        // Validate against storage/order_schema.json and return
        $this->createOrder($data);
        return $this->getOutput();
    }

    /**
     * Extract company information block.
     */
    protected function extractCompany(array $lines, array $keywords): array
    {
        $company = [
            'company' => '',
            'company_code' => '',
            'vat_code' => '',
            'email' => '',
            'contact_person' => '',
            'street_address' => '',
            'title' => '',
            'city' => 'NA',
            'country' => 'DE',
            'postal_code' => '',
            'comment' => '',
        ];

        foreach ($lines as $line) {
            foreach ($keywords as $kw) {
                if (Str::contains(Str::lower($line), Str::lower($kw))) {
                    $parts = explode(':', $line, 2);
                    $value = trim($parts[1] ?? $parts[0]);
                    if (!$company['company']) {
                        $company['company'] = $value;
                    }
                    elseif (!$company['contact_person']) {
                        $company['contact_person'] = $value;
                    }
                    elseif (!$company['street_address']) {
                        $company['street_address'] = $value;
                    }
                    elseif (!$company['country']) {
                        $company['country'] = GeonamesCountry::getIso($value) ?? '';
                    }
                }
            }
        }

        return $this->normalizeCompany($company);
    }

    /**
     * Normalize company data to ensure schema validity.
     */
    protected function normalizeCompany(array $company): array
    {
        $safe = fn($v) => is_string($v) ? trim($v) : (string)($v ?? '');

        // Ensure all values are strings (avoid nulls)
        $company = array_map($safe, $company);

        // City must have at least 2 characters
        if (strlen($company['city']) < 2) {
            $company['city'] = 'NA';
        }

        // Country must be a valid ISO2 code
        if (strlen($company['country']) !== 2) {
            $company['country'] = '';
        }

        return $company;
    }

    /**
     * Extract cargos from lines.
     */
    protected function extractCargos(array $lines): array
    {
        $cargos = [];
        foreach ($lines as $line) {
            if (preg_match('/qty|quantity|weight|pcs|kg/i', $line)) {
                $cols = preg_split('/\s{2,}/', trim($line));
                if (count($cols) >= 2) {
                    $cargos[] = [
                        'title' => $cols[0] ?? 'Cargo',
                        'package_count' => (int) Helper::uncomma($cols[1] ?? 1),
                        'package_type' => 'EPAL',
                        'number' => $cols[2] ?? '',
                        'type' => 'full',
                        'value' => (float) Helper::uncomma($cols[3] ?? 0),
                        'currency' => 'EUR',
                        'pkg_width' => 0,
                        'pkg_length' => 0,
                        'pkg_height' => 0,
                        'ldm' => 0,
                        'volume' => 0,
                        'weight' => (float) Helper::uncomma($cols[4] ?? 0),
                        'chargeable_weight' => 0,
                        'temperature_min' => 0,
                        'temperature_max' => 0,
                        'temperature_mode' => '',
                        'adr' => false,
                        'extra_lift' => false,
                        'palletized' => true,
                        'manual_load' => false,
                        'vehicle_make' => '',
                        'vehicle_model' => '',
                    ];
                }
            }
        }

        if (empty($cargos)) {
            $cargos[] = [
                'title' => 'Default cargo',
                'package_count' => 1,
                'package_type' => 'EPAL',
                'type' => 'full',
                'currency' => 'EUR',
                'value' => 0,
                'weight' => 0,
                'adr' => false,
                'extra_lift' => false,
                'palletized' => true,
                'manual_load' => false,
            ];
        }

        return $cargos;
    }

    /**
     * Extract a value from lines using keywords.
     */
    protected function extractLineValue(array $lines, string $keywords, bool $first = false): ?string
    {
        $keywords = explode('|', $keywords);
        foreach ($lines as $line) {
            foreach ($keywords as $kw) {
                if (Str::contains(Str::lower($line), Str::lower($kw))) {
                    $parts = explode(':', $line, 2);
                    $val = trim($parts[1] ?? $parts[0]);
                    return $val ?: null;
                }
            }
        }
        return null;
    }

    /**
     * Extract date from lines by keyword.
     */
    protected function extractDate(array $lines, string $keyword): ?string
    {
        foreach ($lines as $line) {
            if (Str::contains(Str::lower($line), Str::lower($keyword))) {
                $parts = explode(':', $line, 2);
                try {
                    $date = Carbon::parse(trim($parts[1] ?? $parts[0]));
                    return $date->toIso8601String();
                } catch (Throwable $e) {
                    return null;
                }
            }
        }
        return null;
    }
}
