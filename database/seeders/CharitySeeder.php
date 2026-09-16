<?php

namespace Database\Seeders;

use App\Models\Charity;
use Illuminate\Database\Seeder;

class CharitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $charities = [
            [
                'name' => 'Mecarvi Foundation',
                'tagline' => 'Direct financial assistance & education support',
                'description' => 'Mecarvi Foundation provides direct financial assistance to individuals and communities through education, health support and community development initiatives.',
                'contact_person' => 'Foundation Coordinator',
                'address' => '561 Forest Parkway, Suite 3 Forest Park, GA 30297',
                'phone' => '855-972-5822',
                'email' => 'contact@mecarvifoundation.org',
                'web' => 'www.mecarvifoundation.org',
                'fax' => null,
                'category' => 'Education',
                'status' => 'Active',
                'assistance_tags' => ['Education Support', 'Health Support', 'Community Development'],
                'logo_svg_type' => 'mecarvi_foundation',
            ],
            [
                'name' => 'Green Tomorrow Initiative',
                'tagline' => 'Environmental conservation and sustainable communities',
                'description' => 'Supporting environmental conservation, tree planting and sustainable communities.',
                'contact_person' => 'Environmental Program Lead',
                'address' => '100 Greenway Blvd, Portland, OR 97201',
                'phone' => '(503) 555-0192',
                'email' => 'info@greentomorrow.org',
                'web' => 'www.greentomorrow.org',
                'fax' => null,
                'category' => 'Environment',
                'status' => 'Active',
                'assistance_tags' => ['Tree Planting', 'Conservation', 'Clean Water'],
                'logo_svg_type' => 'green_tomorrow',
            ],
            [
                'name' => 'Paws & Hope',
                'tagline' => 'Shelter, medical care and loving homes for animals',
                'description' => 'Providing shelter, medical care and loving homes for animals in need.',
                'contact_person' => 'Rescue Coordinator',
                'address' => '450 Rescue Way, Austin, TX 78701',
                'phone' => '(512) 555-0188',
                'email' => 'care@pawsandhope.org',
                'web' => 'www.pawsandhope.org',
                'fax' => null,
                'category' => 'Animal Welfare',
                'status' => 'Active',
                'assistance_tags' => ['Animal Rescue', 'Veterinary Care', 'Adoption'],
                'logo_svg_type' => 'paws_and_hope',
            ],
            [
                'name' => 'Feeding America',
                'tagline' => 'Ending hunger nationwide',
                'description' => 'Providing meals to hungry children and families in need across nationwide food banks.',
                'contact_person' => 'Community Outreach',
                'address' => '35 E. Wacker Dr., Suite 2000, Chicago, IL 60601, USA',
                'phone' => '(312) 580-3663',
                'email' => 'info@feedingamerica.org',
                'web' => 'www.feedingamerica.org',
                'fax' => '(312) 580-3680',
                'category' => 'Children',
                'status' => 'Active',
                'assistance_tags' => ['Food Support', 'Hunger Relief'],
                'logo_svg_type' => 'feeding_america',
            ],
            [
                'name' => 'American Red Cross',
                'tagline' => 'Disaster relief and emergency assistance',
                'description' => 'Provides emergency assistance and disaster relief worldwide in times of crisis.',
                'contact_person' => 'Disaster Services',
                'address' => '431 18th Street NW, Washington, DC 20006, USA',
                'phone' => '(800) 733-2767',
                'email' => 'info@redcross.org',
                'web' => 'www.redcross.org',
                'fax' => '(202) 303-0250',
                'category' => 'Disaster Relief',
                'status' => 'Active',
                'assistance_tags' => ['Disaster Relief', 'Emergency Aid'],
                'logo_svg_type' => 'red_cross',
            ],
            [
                'name' => "St. Jude Children's",
                'tagline' => "Finding cures. Saving children's lives.",
                'description' => "Advancing cures and means of prevention for pediatric catastrophic diseases.",
                'contact_person' => 'Hospital Support',
                'address' => '501 St. Jude Place, Memphis, TN 38105, USA',
                'phone' => '(800) 822-6344',
                'email' => 'info@stjude.org',
                'web' => 'www.stjude.org',
                'fax' => '(901) 595-3300',
                'category' => 'Health',
                'status' => 'Active',
                'assistance_tags' => ['Healthcare Support', 'Medical Research'],
                'logo_svg_type' => 'st_jude',
            ]
        ];

        foreach ($charities as $charityData) {
            Charity::updateOrCreate(
                ['name' => $charityData['name']],
                $charityData
            );
        }
    }
}
