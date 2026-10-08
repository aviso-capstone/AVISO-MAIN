<?php

namespace Database\Seeders;

use Rap2hpoutre\FastExcel\FastExcel;
use Yajra\Address\Entities\Barangay;
use Yajra\Address\Entities\City;
use Yajra\Address\Entities\Province;
use Yajra\Address\Entities\Region;

class AddressSeeder extends \Yajra\Address\Seeders\AddressSeeder
{
    public function run(): void
    {
        $publication = base_path(
            'vendor/yajra/laravel-address/database/seeders/publication/PSGC-4Q-2024-Publication-Datafile.xlsx'
        );
        $sheet = config('address.publication.sheet', 4);

        $regions        = [];
        $provinces      = [];
        $cities         = [];
        $barangays      = [];
        $detectedCityId = null;

        $this->command->info('Parsing PSGC data for Zamboanga City...');

        (new FastExcel)
            ->sheet($sheet)
            ->import($publication, function ($line) use (
                &$regions,
                &$provinces,
                &$cities,
                &$barangays,
                &$detectedCityId
            ) {
                $code     = $line['10-digit PSGC'];
                $name     = trim($line['Name']);
                $regionId = substr($code, 0, 2);
                $level    = $line['Geographic Level'];
                $class    = $line['City Class'] ?? '';

                $attrs = [
                    'code'                => $code,
                    'correspondence_code' => $line['Correspondence Code'] ?? '',
                    'name'                => $name,
                    'region_id'           => $regionId,
                ];

                switch ($level) {
                    case 'Reg':
                        if ($regionId === '09') {
                            $regions[] = $attrs;
                        }
                        break;

                    case 'Prov':
                    case 'Dist':
                    case '':
                        if ($regionId !== '09') {
                            return;
                        }
                        if (str_contains(strtolower($name), 'zamboanga del sur')) {
                            $provinces[] = array_merge($attrs, ['province_id' => substr($code, 0, 5)]);
                        }
                        break;

                    case 'Bgy':
                        if (! $detectedCityId) {
                            return;
                        }
                        if (substr($code, 0, 7) !== $detectedCityId) {
                            return;
                        }
                        $barangays[] = array_merge($attrs, [
                            'province_id' => substr($code, 0, 5),
                            'city_id'     => $detectedCityId,
                        ]);
                        break;

                    default: // City, Mun, SubMun
                        if ($regionId !== '09') {
                            return;
                        }
                        $lower           = strtolower($name);
                        $isZamboangaCity = str_contains($lower, 'zamboanga')
                            && ! str_contains($lower, 'del sur')
                            && ! str_contains($lower, 'sibugay')
                            && ! str_contains($lower, 'norte');

                        if (! $isZamboangaCity) {
                            return;
                        }

                        $cityId         = substr($code, 0, 7);
                        $detectedCityId = $cityId;
                        $cityName       = str_replace('City of ', '', $name);

                        $cities[] = array_merge($attrs, [
                            'name'        => $cityName,
                            'province_id' => substr($code, 0, 5),
                            'city_id'     => $cityId,
                        ]);

                        // HUC cities also appear in provinces as their own virtual province
                        if ($class === 'HUC') {
                            $provinces[] = [
                                'code'                => $code,
                                'correspondence_code' => $attrs['correspondence_code'],
                                'name'                => $name,
                                'region_id'           => $regionId,
                                'province_id'         => substr($code, 0, 5),
                            ];
                        }
                        break;
                }
            });

        Region::query()->insertOrIgnore($regions);
        Province::query()->insertOrIgnore($provinces);
        City::query()->insertOrIgnore($cities);

        collect($barangays)->chunk(50)->each(
            fn ($chunk) => Barangay::query()->insertOrIgnore($chunk->toArray())
        );

        $this->command->info(sprintf(
            'Done: %d region, %d province(s), %d city, %d barangay(s) seeded for Zamboanga City.',
            count($regions),
            count($provinces),
            count($cities),
            count($barangays)
        ));
    }
}
