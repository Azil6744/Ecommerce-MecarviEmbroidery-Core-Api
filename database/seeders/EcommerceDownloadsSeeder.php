<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\EcommerceCustomerFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class EcommerceDownloadsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $user1 = User::create([
                'name' => 'Alexander Wright',
                'email' => 'alex.wright@example.com',
                'password' => bcrypt('password123'),
                'role' => 'customer',
            ]);
            $user2 = User::create([
                'name' => 'Elena Rostova',
                'email' => 'elena@apollodesigns.com',
                'password' => bcrypt('password123'),
                'role' => 'business',
                'business_name' => 'Apollo Design Studio',
            ]);
            $users = collect([$user1, $user2]);
        }

        $userList = $users->values();

        $sampleFiles = [
            [
                'file_name' => 'Custom Gold Crest Embroidery Digitized',
                'file_path' => 'downloads/samples/gold_crest_embroidery.dst',
                'file_type' => 'dst',
                'category' => 'Embroidery',
                'size_bytes' => 4404019, // ~4.2 MB
                'download_count' => 19,
                'description' => 'High-density gold crest digitized pattern for Tajima embroidery machines.',
                'notes' => 'Use Madeira 40wt thread. 120mm x 95mm hoop.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Luxury Apparel Vector Brand Logo Pack',
                'file_path' => 'downloads/samples/luxury_vector_logo_pack.ai',
                'file_type' => 'ai',
                'category' => 'Vector Art',
                'size_bytes' => 13107200, // ~12.5 MB
                'download_count' => 38,
                'description' => 'CMYK vector master artwork with curved typography and outlined paths.',
                'notes' => 'Adobe Illustrator CC format with layered colorways.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Vintage Bomber Jacket Back Digitizing',
                'file_path' => 'downloads/samples/vintage_bomber_digitizing.emb',
                'file_type' => 'emb',
                'category' => 'Digitizing',
                'size_bytes' => 9122611, // ~8.7 MB
                'download_count' => 12,
                'description' => 'Wilcom native embroidery design file with custom underlay settings.',
                'notes' => 'Designed for 280mm x 200mm jacket backs.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'High-Res Screen Print Cap Mockups',
                'file_path' => 'downloads/samples/cap_mockups.psd',
                'file_type' => 'psd',
                'category' => 'Graphics',
                'size_bytes' => 40265318, // ~38.4 MB
                'download_count' => 24,
                'description' => 'Photoshop smart object templates for embroidered and printed caps.',
                'notes' => 'Includes 3 angles and realistic stitch texture overlay.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Corporate Identity Print-Ready Assets',
                'file_path' => 'downloads/samples/corporate_guidelines.pdf',
                'file_type' => 'pdf',
                'category' => 'Print Ready',
                'size_bytes' => 26004684, // ~24.8 MB
                'download_count' => 45,
                'description' => 'Press-ready PDF with bleed, trim marks and Pantone color specs.',
                'notes' => 'Approved by client. Ready for Heidelberg offset printing.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Complete Monogram Alphabet Patch Pack',
                'file_path' => 'downloads/samples/monogram_patch_pack.zip',
                'file_type' => 'zip',
                'category' => 'Embroidery',
                'size_bytes' => 20132659, // ~19.2 MB
                'download_count' => 31,
                'description' => 'Full A-Z satin-stitch monogram alphabet in DST, PES, EXP, and JEF formats.',
                'notes' => 'Contains sizes: 1.5 inch, 2.5 inch, and 4 inch heights.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Transparent High-DPI Watermark Elements',
                'file_path' => 'downloads/samples/watermark_assets.png',
                'file_type' => 'png',
                'category' => 'Graphics',
                'size_bytes' => 5872025, // ~5.6 MB
                'download_count' => 57,
                'description' => '300 DPI transparent PNG exports for digital and web overlays.',
                'notes' => 'Includes black, white, and iridescent foil variations.',
                'status' => 'Published',
            ],
            [
                'file_name' => 'Neon Glow Snapback Digitized Pattern',
                'file_path' => 'downloads/samples/neon_snapback.pes',
                'file_type' => 'pes',
                'category' => 'Digitizing',
                'size_bytes' => 3250585, // ~3.1 MB
                'download_count' => 8,
                'description' => 'Optimized for Brother and BabyLock multi-needle machines.',
                'notes' => 'High-density satin border for 3D puff embroidery.',
                'status' => 'Published',
            ],
        ];

        foreach ($sampleFiles as $index => $item) {
            $assignedUser = $userList[$index % $userList->count()];

            if (!Storage::disk('public')->exists($item['file_path'])) {
                Storage::disk('public')->put($item['file_path'], 'Mecarvi Embroidery sample download file content for ' . $item['file_name']);
            }

            EcommerceCustomerFile::updateOrCreate(
                [
                    'user_id' => $assignedUser->id,
                    'file_name' => $item['file_name'],
                ],
                [
                    'file_path' => $item['file_path'],
                    'file_type' => $item['file_type'],
                    'category' => $item['category'],
                    'size_bytes' => $item['size_bytes'],
                    'download_count' => $item['download_count'],
                    'description' => $item['description'],
                    'notes' => $item['notes'],
                    'status' => $item['status'],
                ]
            );
        }

        $this->command->info('Seeded ' . count($sampleFiles) . ' sample download files successfully.');
    }
}
