<?php

namespace App\Support;

/**
 * The countries a tenant can be based in, with the states or provinces each
 * offers. Values are full names so the state stored on existing tenants
 * ("Pennsylvania") keeps matching the dropdown.
 */
class Countries
{
    private const COUNTRIES = [
        'US' => ['name' => 'United States', 'subdivisions' => [
            'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado',
            'Connecticut', 'Delaware', 'District of Columbia', 'Florida', 'Georgia',
            'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky',
            'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota',
            'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire',
            'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota',
            'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island',
            'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont',
            'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming',
        ]],
        'CA' => ['name' => 'Canada', 'subdivisions' => [
            'Alberta', 'British Columbia', 'Manitoba', 'New Brunswick',
            'Newfoundland and Labrador', 'Northwest Territories', 'Nova Scotia',
            'Nunavut', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan',
            'Yukon',
        ]],
        'AU' => ['name' => 'Australia', 'subdivisions' => [
            'Australian Capital Territory', 'New South Wales', 'Northern Territory',
            'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia',
        ]],
        'NZ' => ['name' => 'New Zealand', 'subdivisions' => [
            'Auckland', 'Bay of Plenty', 'Canterbury', 'Gisborne', "Hawke's Bay",
            'Manawatū-Whanganui', 'Marlborough', 'Nelson', 'Northland', 'Otago',
            'Southland', 'Taranaki', 'Tasman', 'Waikato', 'Wellington', 'West Coast',
        ]],
        'GB' => ['name' => 'United Kingdom', 'subdivisions' => [
            'England', 'Northern Ireland', 'Scotland', 'Wales',
        ]],
        'ZA' => ['name' => 'South Africa', 'subdivisions' => [
            'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo',
            'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape',
        ]],
        'IN' => ['name' => 'India', 'subdivisions' => [
            'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh',
            'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh',
            'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat',
            'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand',
            'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh',
            'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha',
            'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
            'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        ]],
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::COUNTRIES);
    }

    /**
     * The states or provinces for a country, empty when we have no list for it.
     *
     * @return array<int, string>
     */
    public static function subdivisions(?string $country): array
    {
        return self::COUNTRIES[strtoupper((string) $country)]['subdivisions'] ?? [];
    }

    /**
     * What the country/state form script needs to rebuild the state list.
     *
     * @return array<string, array{name: string, subdivisions: array<int, string>}>
     */
    public static function options(): array
    {
        return self::COUNTRIES;
    }
}
