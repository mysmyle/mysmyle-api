<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Country reference data, plus every nationality spelling the legacy data uses.
 *
 * IDS ARE THE LEGACY country_id. That is deliberate and load-bearing: legacy
 * patient_registration.Nationality holds the legacy countries.nationality STRING,
 * and other legacy tables reference countries by that id. Preserving it is the
 * same convention LegacyStaffImporter and LegacyUserImporter already follow
 * (staff.id = employee_id, users.id = login_id).
 *
 * The country row carries ONE canonical demonym — the first element of the
 * legacy comma list. Every other spelling becomes a row in
 * country_nationality_aliases, which is what makes the patient import exact.
 *
 * Why aliases matter, measured on 10,418 legacy patients with a nationality:
 * the previous importer matched only the clean demonym and the country name, so
 * it silently dropped the nationality of 3,132 patients — including everyone
 * recorded as "Emirati, Emirian, Emiri" (2,067), "Philippine, Filipino" (679)
 * and "British, UK" (175). With aliases, those match exactly.
 *
 * Phone codes are not in the legacy table at all; they come from the ISO list.
 */
class CountrySeeder extends Seeder
{
    /**
     * Precedence when two countries claim the same spelling — lower wins.
     * 15 spellings collide; this ordering resolves all but two of them, and
     * resolves them CORRECTLY:
     *   "Indian"   -> India, not the British Indian Ocean Territory (527 patients)
     *   "Nigerian" -> Nigeria, not Niger. Legacy has those two countries
     *                 shafafiya codes SWAPPED, which is why shafafiya ranks last
     *   "Chinese"  -> China, not Macao or Taiwan
     *   "French"   -> France, not French Guiana / Polynesia / Southern Territories
     */
    private const SOURCE_PRECEDENCE = [
        'manual' => 0,
        'nationality' => 1,
        'nationality_part' => 2,
        'en_short_name' => 3,
        'shafafiya' => 4,
    ];

    /**
     * Spellings two countries claim with EQUAL precedence, so no rule can pick a
     * winner. Both occur in real patient data, so the choice is recorded here
     * rather than left to whichever row happened to be inserted first.
     *
     *   "American"  — United States (237) vs United States Minor Outlying
     *                 Islands (236). 201 patients. Nobody means the latter.
     *   "Dominican" — Dominican Republic (64) vs Dominica (63). 17 patients.
     *                 The Republic is the far larger source of UAE residents.
     *
     * The importer still writes a nationality_ambiguous review issue for every
     * patient resolved this way, so the assumption stays visible.
     */
    private const MANUAL_ALIASES = [
        'American' => 237,
        'Dominican' => 64,

        // Demonyms that appear in patient data but in no legacy country column,
        // so nothing could ever have matched them. The previous importer dropped
        // every one. Unambiguous, so they are simply added rather than queued
        // for review.
        'Swede' => 215,          // Sweden
        'Dutchman' => 157,       // Netherlands
        'Luxembourger' => 130,   // Luxembourg
        'Pole' => 177,           // Poland
        'Spaniard' => 209,       // Spain
        'Monacan' => 147,        // Monaco
    ];

    /** [id, num_code, alpha_2, alpha_3, en_short_name, legacy_nationality, shafafiya, phone_code] */
    private const COUNTRIES = [
            [1, 4, 'AF', 'AFG', 'Afghanistan', 'Afghan', 'Afghan', '93'],
            [2, 248, 'AX', 'ALA', 'Aland Islands', 'Aland Island', 'Alanders', '358'],
            [3, 8, 'AL', 'ALB', 'Albania', 'Albanian', 'Albanian', '355'],
            [4, 12, 'DZ', 'DZA', 'Algeria', 'Algerian', 'Algerian', '213'],
            [5, 16, 'AS', 'ASM', 'American Samoa', 'American Samoan', 'American', '1684'],
            [6, 20, 'AD', 'AND', 'Andorra', 'Andorran', 'Andorran', '376'],
            [7, 24, 'AO', 'AGO', 'Angola', 'Angolan', 'Angolan', '244'],
            [8, 660, 'AI', 'AIA', 'Anguilla', 'Anguillan', 'Anguillian', '1264'],
            [9, 10, 'AQ', 'ATA', 'Antarctica', 'Antarctic', '', '0'],
            [10, 28, 'AG', 'ATG', 'Antigua and Barbuda', 'Antiguan,Barbudan', 'Antiguan', '1268'],
            [11, 32, 'AR', 'ARG', 'Argentina', 'Argentine', 'Argentine', '54'],
            [12, 51, 'AM', 'ARM', 'Armenia', 'Armenian', 'Armenian', '374'],
            [13, 533, 'AW', 'ABW', 'Aruba', 'Aruban', 'Arubian', '297'],
            [14, 36, 'AU', 'AUS', 'Australia', 'Australian', 'Australian', '61'],
            [15, 40, 'AT', 'AUT', 'Austria', 'Austrian', 'Austrian', '43'],
            [16, 31, 'AZ', 'AZE', 'Azerbaijan', 'Azerbaijani, Azeri', 'Azerbaijani', '994'],
            [17, 44, 'BS', 'BHS', 'Bahamas', 'Bahamian', 'Bahamian', '1242'],
            [18, 48, 'BH', 'BHR', 'Bahrain', 'Bahraini', 'Bahraini', '973'],
            [19, 50, 'BD', 'BGD', 'Bangladesh', 'Bangladeshi', 'Bangladeshi', '880'],
            [20, 52, 'BB', 'BRB', 'Barbados', 'Barbadian', 'Barbadian', '1246'],
            [21, 112, 'BY', 'BLR', 'Belarus', 'Belarusian', 'Belarusian', '375'],
            [22, 56, 'BE', 'BEL', 'Belgium', 'Belgian', 'Belgian', '32'],
            [23, 84, 'BZ', 'BLZ', 'Belize', 'Belizean', 'Belizean', '501'],
            [24, 204, 'BJ', 'BEN', 'Benin', 'Beninese,Beninois', 'Beninese', '229'],
            [25, 60, 'BM', 'BMU', 'Bermuda', 'Bermudian,Bermudan', 'Bermudain', '1441'],
            [26, 64, 'BT', 'BTN', 'Bhutan', 'Bhutanese', 'Bhutanese', '975'],
            [27, 68, 'BO', 'BOL', 'Bolivia (Plurinational State of)', 'Bolivian', 'Bolivian', '591'],
            [28, 535, 'BQ', 'BES', 'Bonaire, Sint Eustatius and Saba', 'Bonaire', 'Bermuda', '599'],
            [29, 70, 'BA', 'BIH', 'Bosnia and Herzegovina', 'Bosnian or Herzegovinian', 'Bosnian', '387'],
            [30, 72, 'BW', 'BWA', 'Botswana', 'Motswana, Botswanan', 'Motswana', '267'],
            [31, 74, 'BV', 'BVT', 'Bouvet Island', 'Bouvet Island', '', '0'],
            [32, 76, 'BR', 'BRA', 'Brazil', 'Brazilian', 'Brazilian', '55'],
            [33, 86, 'IO', 'IOT', 'British Indian Ocean Territory', 'BIOT', 'Indian', '246'],
            [34, 96, 'BN', 'BRN', 'Brunei Darussalam', 'Bruneian', 'Bruneian', '673'],
            [35, 100, 'BG', 'BGR', 'Bulgaria', 'Bulgarian', 'Bulgarian', '359'],
            [36, 854, 'BF', 'BFA', 'Burkina Faso', 'Burkinabe', 'Burkinabe', '226'],
            [37, 108, 'BI', 'BDI', 'Burundi', 'Burundian', 'Burundian', '257'],
            [38, 132, 'CV', 'CPV', 'Cabo Verde', 'Cabo Verdean', 'Cape Verdean', '238'],
            [39, 116, 'KH', 'KHM', 'Cambodia', 'Cambodian', 'Cambodian', '855'],
            [40, 120, 'CM', 'CMR', 'Cameroon', 'Cameroonian', 'Cameroonian', '237'],
            [41, 124, 'CA', 'CAN', 'Canada', 'Canadian', 'Canadian', '1'],
            [42, 136, 'KY', 'CYM', 'Cayman Islands', 'Caymanian', 'Cayman Islanders', '1345'],
            [43, 140, 'CF', 'CAF', 'Central African Republic', 'Central African', 'Central African', '236'],
            [44, 148, 'TD', 'TCD', 'Chad', 'Chadian', 'Chadian', '235'],
            [45, 152, 'CL', 'CHL', 'Chile', 'Chilean', 'Chilean', '56'],
            [46, 156, 'CN', 'CHN', 'China', 'Chinese', 'Chinese', '86'],
            [47, 162, 'CX', 'CXR', 'Christmas Island', 'Christmas Island', '', '61'],
            [48, 166, 'CC', 'CCK', 'Cocos (Keeling) Islands', 'Cocos Island', '', '672'],
            [49, 170, 'CO', 'COL', 'Colombia', 'Colombian', 'Colombian', '57'],
            [50, 174, 'KM', 'COM', 'Comoros', 'Comoran, Comorian', 'Comoran', '269'],
            [51, 178, 'CG', 'COG', 'Congo (Republic of the)', 'Congolese', 'Congolese', '242'],
            [52, 180, 'CD', 'COD', 'Congo (Democratic Republic of the)', 'Congolese', 'Congolese', '243'],
            [53, 184, 'CK', 'COK', 'Cook Islands', 'Cook Island', 'Cook Islander', '682'],
            [54, 188, 'CR', 'CRI', 'Costa Rica', 'Costa Rican', 'Costa Rican', '506'],
            [55, 384, 'CI', 'CIV', 'Cote dIvoire', 'Ivorian', 'French Polynesian', '225'],
            [56, 191, 'HR', 'HRV', 'Croatia', 'Croatian', 'Croatian', '385'],
            [57, 192, 'CU', 'CUB', 'Cuba', 'Cuban', 'Cuban', '53'],
            [58, 531, 'CW', 'CUW', 'Curacao', 'Curacaoan', 'Curacao', '599'],
            [59, 196, 'CY', 'CYP', 'Cyprus', 'Cypriot', 'Cypriot', '357'],
            [60, 203, 'CZ', 'CZE', 'Czech Republic', 'Czech', 'Czech', '420'],
            [61, 208, 'DK', 'DNK', 'Denmark', 'Danish', 'Danish', '45'],
            [62, 262, 'DJ', 'DJI', 'Djibouti', 'Djiboutian', 'Djiboutian', '253'],
            [63, 212, 'DM', 'DMA', 'Dominica', 'Dominican', 'Dominican', '1767'],
            [64, 214, 'DO', 'DOM', 'Dominican Republic', 'Dominican', 'Dominican', '1809'],
            [65, 218, 'EC', 'ECU', 'Ecuador', 'Ecuadorian', 'Ecuadorian', '593'],
            [66, 818, 'EG', 'EGY', 'Egypt', 'Egyptian', 'Egyptian', '20'],
            [67, 222, 'SV', 'SLV', 'El Salvador', 'Salvadoran', 'Salvadoran', '503'],
            [68, 226, 'GQ', 'GNQ', 'Equatorial Guinea', 'Equatorial Guinean, Equatoguinean', 'Equatoguinean', '240'],
            [69, 232, 'ER', 'ERI', 'Eritrea', 'Eritrean', 'Eritrean', '291'],
            [70, 233, 'EE', 'EST', 'Estonia', 'Estonian', 'Estonian', '372'],
            [71, 231, 'ET', 'ETH', 'Ethiopia', 'Ethiopian', 'Ethiopian', '251'],
            [72, 238, 'FK', 'FLK', 'Falkland Islands (Malvinas)', 'Falkland Island', 'Falkland Islanders (Malvinas)', '500'],
            [73, 234, 'FO', 'FRO', 'Faroe Islands', 'Faroese', 'Faroe Islanders', '298'],
            [74, 242, 'FJ', 'FJI', 'Fiji', 'Fijian', 'Fijian', '679'],
            [75, 246, 'FI', 'FIN', 'Finland', 'Finnish', 'Finnish', '358'],
            [76, 250, 'FR', 'FRA', 'France', 'French', 'French', '33'],
            [77, 254, 'GF', 'GUF', 'French Guiana', 'French Guianese', 'French', '594'],
            [78, 258, 'PF', 'PYF', 'French Polynesia', 'French Polynesian', 'French', '689'],
            [79, 260, 'TF', 'ATF', 'French Southern Territories', 'French Southern Territories', 'French', '0'],
            [80, 266, 'GA', 'GAB', 'Gabon', 'Gabonese', 'Gabonese', '241'],
            [81, 270, 'GM', 'GMB', 'Gambia', 'Gambian', 'Gambian', '220'],
            [82, 268, 'GE', 'GEO', 'Georgia', 'Georgian', 'Georgian', '995'],
            [83, 276, 'DE', 'DEU', 'Germany', 'German', 'German', '49'],
            [84, 288, 'GH', 'GHA', 'Ghana', 'Ghanaian', 'Ghanaian', '233'],
            [85, 292, 'GI', 'GIB', 'Gibraltar', 'Gibraltar', 'Gibraltarian', '350'],
            [86, 300, 'GR', 'GRC', 'Greece', 'Greek, Hellenic', 'Greek', '30'],
            [87, 304, 'GL', 'GRL', 'Greenland', 'Greenlandic', 'Greenlander', '299'],
            [88, 308, 'GD', 'GRD', 'Grenada', 'Grenadian', 'Grenadian', '1473'],
            [89, 312, 'GP', 'GLP', 'Guadeloupe', 'Guadeloupe', 'Guadeloupean', '590'],
            [90, 316, 'GU', 'GUM', 'Guam', 'Guamanian, Guambat', 'Gguamanian', '1671'],
            [91, 320, 'GT', 'GTM', 'Guatemala', 'Guatemalan', 'Guatemalan', '502'],
            [92, 831, 'GG', 'GGY', 'Guernsey', 'Channel Island', 'Guernsey', '44'],
            [93, 324, 'GN', 'GIN', 'Guinea', 'Guinean', 'Guinean', '224'],
            [94, 624, 'GW', 'GNB', 'Guinea-Bissau', 'Bissau-Guinean', 'Guinean', '245'],
            [95, 328, 'GY', 'GUY', 'Guyana', 'Guyanese', 'Guyanese', '592'],
            [96, 332, 'HT', 'HTI', 'Haiti', 'Haitian', 'Haitian', '509'],
            [97, 334, 'HM', 'HMD', 'Heard Island and McDonald Islands', 'Heard Island or McDonald Islands', '', '0'],
            [98, 336, 'VA', 'VAT', 'Vatican City State', 'Vatican', 'Vatican', '39'],
            [99, 340, 'HN', 'HND', 'Honduras', 'Honduran', 'Honduran', '504'],
            [100, 344, 'HK', 'HKG', 'Hong Kong', 'Hong Kong, Hong Kongese', 'Hong Kong', '852'],
            [101, 348, 'HU', 'HUN', 'Hungary', 'Hungarian, Magyar', 'Hungarian', '36'],
            [102, 352, 'IS', 'ISL', 'Iceland', 'Icelandic', 'Icelandic', '354'],
            [103, 356, 'IN', 'IND', 'India', 'Indian', 'Indian', '91'],
            [104, 360, 'ID', 'IDN', 'Indonesia', 'Indonesian', 'Indonesian', '62'],
            [105, 364, 'IR', 'IRN', 'Iran', 'Iranian, Persian', 'Iranian', '98'],
            [106, 368, 'IQ', 'IRQ', 'Iraq', 'Iraqi', 'Iraqi', '964'],
            [107, 372, 'IE', 'IRL', 'Ireland', 'Irish', 'Irish', '353'],
            [108, 833, 'IM', 'IMN', 'Isle of Man', 'Manx', '', '44'],
            [109, 376, 'IL', 'ISR', 'Israel', 'Israeli', 'Israeli', '972'],
            [110, 380, 'IT', 'ITA', 'Italy', 'Italian', 'Italian', '39'],
            [111, 388, 'JM', 'JAM', 'Jamaica', 'Jamaican', 'Jamaican', '1876'],
            [112, 392, 'JP', 'JPN', 'Japan', 'Japanese', 'Japanese', '81'],
            [113, 832, 'JE', 'JEY', 'Jersey', 'Channel Island', 'Jersey', '44'],
            [114, 400, 'JO', 'JOR', 'Jordan', 'Jordanian', 'Jordanian', '962'],
            [115, 398, 'KZ', 'KAZ', 'Kazakhstan', 'Kazakhstani, Kazakh', 'Kazakhstani', '7'],
            [116, 404, 'KE', 'KEN', 'Kenya', 'Kenyan', 'Kenyan', '254'],
            [117, 296, 'KI', 'KIR', 'Kiribati', 'I-Kiribati', 'I-Kiribati', '686'],
            [118, 408, 'KP', 'PRK', 'Korea (Democratic Peoples Republic of)', 'North Korean', 'Korean', '850'],
            [119, 410, 'KR', 'KOR', 'Korea (Republic of)', 'South Korean', 'Korean', '82'],
            [120, 414, 'KW', 'KWT', 'Kuwait', 'Kuwaiti', 'Kuwaiti', '965'],
            [121, 417, 'KG', 'KGZ', 'Kyrgyzstan', 'Kyrgyzstani, Kyrgyz, Kirgiz, Kirghiz', 'Kyrgyzstani', '996'],
            [122, 418, 'LA', 'LAO', 'Lao Peoples Democratic Republic', 'Lao, Laotian', 'Laotian', '856'],
            [123, 428, 'LV', 'LVA', 'Latvia', 'Latvian', 'Latvian', '371'],
            [124, 422, 'LB', 'LBN', 'Lebanon', 'Lebanese', 'Lebanese', '961'],
            [125, 426, 'LS', 'LSO', 'Lesotho', 'Basotho', 'Basotho', '266'],
            [126, 430, 'LR', 'LBR', 'Liberia', 'Liberian', 'Liberian', '231'],
            [127, 434, 'LY', 'LBY', 'Libya', 'Libyan', 'Libyan', '218'],
            [128, 438, 'LI', 'LIE', 'Liechtenstein', 'Liechtenstein', 'Liechtenstein', '423'],
            [129, 440, 'LT', 'LTU', 'Lithuania', 'Lithuanian', 'Lithuanian', '370'],
            [130, 442, 'LU', 'LUX', 'Luxembourg', 'Luxembourg, Luxembourgish', 'Luxembourg', '352'],
            [131, 446, 'MO', 'MAC', 'Macao', 'Macanese, Chinese', 'Macau', '853'],
            [132, 807, 'MK', 'MKD', 'Macedonia (the former Yugoslav Republic of)', 'Macedonian', 'Macedonian', '389'],
            [133, 450, 'MG', 'MDG', 'Madagascar', 'Malagasy', 'Malagasy', '261'],
            [134, 454, 'MW', 'MWI', 'Malawi', 'Malawian', 'Malawian', '265'],
            [135, 458, 'MY', 'MYS', 'Malaysia', 'Malaysian', 'Malaysian', '60'],
            [136, 462, 'MV', 'MDV', 'Maldives', 'Maldivian', 'Maldivian', '960'],
            [137, 466, 'ML', 'MLI', 'Mali', 'Malian, Malinese', 'Malian', '223'],
            [138, 470, 'MT', 'MLT', 'Malta', 'Maltese', 'Maltese', '356'],
            [139, 584, 'MH', 'MHL', 'Marshall Islands', 'Marshallese', 'Marshallese', '692'],
            [140, 474, 'MQ', 'MTQ', 'Martinique', 'Martiniquais, Martinican', 'Martinique', '596'],
            [141, 478, 'MR', 'MRT', 'Mauritania', 'Mauritanian', 'Mauritanian', '222'],
            [142, 480, 'MU', 'MUS', 'Mauritius', 'Mauritian', 'Mauritian', '230'],
            [143, 175, 'YT', 'MYT', 'Mayotte', 'Mahoran', 'Mayottian', '262'],
            [144, 484, 'MX', 'MEX', 'Mexico', 'Mexican', 'Mexican', '52'],
            [145, 583, 'FM', 'FSM', 'Micronesia (Federated States of)', 'Micronesian', 'Micronesian', '691'],
            [146, 498, 'MD', 'MDA', 'Moldova (Republic of)', 'Moldovan', 'Moldovan', '373'],
            [147, 492, 'MC', 'MCO', 'Monaco', 'Monégasque, Monacan', 'Monegasque', '377'],
            [148, 496, 'MN', 'MNG', 'Mongolia', 'Mongolian', 'Mongolian', '976'],
            [149, 499, 'ME', 'MNE', 'Montenegro', 'Montenegrin', 'Montenegrin', '382'],
            [150, 500, 'MS', 'MSR', 'Montserrat', 'Montserratian', 'Montserrain', '1664'],
            [151, 504, 'MA', 'MAR', 'Morocco', 'Moroccan', 'Moroccan', '212'],
            [152, 508, 'MZ', 'MOZ', 'Mozambique', 'Mozambican', 'Mozambican', '258'],
            [153, 104, 'MM', 'MMR', 'Myanmar', 'Burmese', 'Myanmarese', '95'],
            [154, 516, 'NA', 'NAM', 'Namibia', 'Namibian', 'Namibian', '264'],
            [155, 520, 'NR', 'NRU', 'Nauru', 'Nauruan', 'Nauruan', '674'],
            [156, 524, 'NP', 'NPL', 'Nepal', 'Nepali, Nepalese', 'Nepalese', '977'],
            [157, 528, 'NL', 'NLD', 'Netherlands', 'Dutch, Netherlandic', 'Dutch', '31'],
            [158, 540, 'NC', 'NCL', 'New Caledonia', 'New Caledonian', 'New Caledonian', '687'],
            [159, 554, 'NZ', 'NZL', 'New Zealand', 'New Zealand, NZ', 'New Zealand', '64'],
            [160, 558, 'NI', 'NIC', 'Nicaragua', 'Nicaraguan', 'Nicaraguan', '505'],
            [161, 562, 'NE', 'NER', 'Niger', 'Nigerien', 'Nigerian', '227'],
            [162, 566, 'NG', 'NGA', 'Nigeria', 'Nigerian', 'Nigerien', '234'],
            [163, 570, 'NU', 'NIU', 'Niue', 'Niuean', 'Niuean', '683'],
            [164, 574, 'NF', 'NFK', 'Norfolk Island', 'Norfolk Island', 'Norfolk Islander', '672'],
            [165, 580, 'MP', 'MNP', 'Northern Mariana Islands', 'Northern Marianan', 'Northern Mariana Islander', '1670'],
            [166, 578, 'NO', 'NOR', 'Norway', 'Norwegian', 'Norwegian', '47'],
            [167, 512, 'OM', 'OMN', 'Oman', 'Omani', 'Omani', '968'],
            [168, 586, 'PK', 'PAK', 'Pakistan', 'Pakistani', 'Pakistani', '92'],
            [169, 585, 'PW', 'PLW', 'Palau', 'Palauan', 'Palauan', '680'],
            [170, 275, 'PS', 'PSE', 'Palestine, State of', 'Palestinian', 'Palestinian', '970'],
            [171, 591, 'PA', 'PAN', 'Panama', 'Panamanian', 'Panamanian', '507'],
            [172, 598, 'PG', 'PNG', 'Papua New Guinea', 'Papua New Guinean, Papuan', 'Papua New Guinean', '675'],
            [173, 600, 'PY', 'PRY', 'Paraguay', 'Paraguayan', 'Paraguayan', '595'],
            [174, 604, 'PE', 'PER', 'Peru', 'Peruvian', 'Peruvian', '51'],
            [175, 608, 'PH', 'PHL', 'Philippines', 'Philippine, Filipino', 'Philippine', '63'],
            [176, 612, 'PN', 'PCN', 'Pitcairn', 'Pitcairn Island', 'Pitcairn', '64'],
            [177, 616, 'PL', 'POL', 'Poland', 'Polish', 'Polish', '48'],
            [178, 620, 'PT', 'PRT', 'Portugal', 'Portuguese', 'Portuguese', '351'],
            [179, 630, 'PR', 'PRI', 'Puerto Rico', 'Puerto Rican', 'Puerto Rico', '1787'],
            [180, 634, 'QA', 'QAT', 'Qatar', 'Qatari', 'Qatari', '974'],
            [181, 638, 'RE', 'REU', 'RÃ©union', 'Reunionese, Reunionnais', 'Reunion', '262'],
            [182, 642, 'RO', 'ROU', 'Romania', 'Romanian', 'Romanian', '40'],
            [183, 643, 'RU', 'RUS', 'Russian Federation', 'Russian', 'Russian', '7'],
            [184, 646, 'RW', 'RWA', 'Rwanda', 'Rwandan', 'Rwandan', '250'],
            [185, 652, 'BL', 'BLM', 'Saint Barthelemy', 'Barthelemois', 'Saint Barthelemy', '590'],
            [186, 654, 'SH', 'SHN', 'Saint Helena, Ascension and Tristan da Cunha', 'Saint Helenian', 'Norfolk Islander', '290'],
            [187, 659, 'KN', 'KNA', 'Saint Kitts and Nevis', 'Kittitian or Nevisian', 'Kittitian', '1869'],
            [188, 662, 'LC', 'LCA', 'Saint Lucia', 'Saint Lucian', 'Saint Lucian', '1758'],
            [189, 663, 'MF', 'MAF', 'Saint Martin (French part)', 'Saint-Martinoise', 'Saint Martin (French Part)', '590'],
            [190, 666, 'PM', 'SPM', 'Saint Pierre and Miquelon', 'Saint-Pierrais or Miquelonnais', 'Saint Pierre And Miquelon', '508'],
            [191, 670, 'VC', 'VCT', 'Saint Vincent and the Grenadines', 'Saint Vincentian, Vincentian', 'Vincentian', '1784'],
            [192, 882, 'WS', 'WSM', 'Samoa', 'Samoan', 'Samoan', '685'],
            [193, 674, 'SM', 'SMR', 'San Marino', 'Sammarinese', 'Sammarinese', '378'],
            [194, 678, 'ST', 'STP', 'Sao Tome and Principe', 'Sao Tomean', 'Sao Tomean', '239'],
            [195, 682, 'SA', 'SAU', 'Saudi Arabia', 'Saudi, Saudi Arabian', 'Saudi', '966'],
            [196, 686, 'SN', 'SEN', 'Senegal', 'Senegalese', 'Senegalese', '221'],
            [197, 688, 'RS', 'SRB', 'Serbia', 'Serbian', 'Serbian', '381'],
            [198, 690, 'SC', 'SYC', 'Seychelles', 'Seychellois', 'Seychellois', '248'],
            [199, 694, 'SL', 'SLE', 'Sierra Leone', 'Sierra Leonean', 'Sierra Leonean', '232'],
            [200, 702, 'SG', 'SGP', 'Singapore', 'Singaporean', 'Singapore', '65'],
            [201, 534, 'SX', 'SXM', 'Sint Maarten (Dutch part)', 'Sint Maarten', 'Sint Maarten (Dutch Part)', '1721'],
            [202, 703, 'SK', 'SVK', 'Slovakia', 'Slovak', 'Slovak', '421'],
            [203, 705, 'SI', 'SVN', 'Slovenia', 'Slovenian, Slovene', 'Slovenian', '386'],
            [204, 90, 'SB', 'SLB', 'Solomon Islands', 'Solomon Island', 'Solomon Islander', '677'],
            [205, 706, 'SO', 'SOM', 'Somalia', 'Somali, Somalian', 'Somali', '252'],
            [206, 710, 'ZA', 'ZAF', 'South Africa', 'South African', 'South African', '27'],
            [207, 239, 'GS', 'SGS', 'South Georgia and the South Sandwich Islands', 'South Georgia, South Sandwich Islands', 'Georgian', '500'],
            [208, 728, 'SS', 'SSD', 'South Sudan', 'South Sudanese', 'South Sudanese', '211'],
            [209, 724, 'ES', 'ESP', 'Spain', 'Spanish', 'Spanish', '34'],
            [210, 144, 'LK', 'LKA', 'Sri Lanka', 'Sri Lankan', 'Sri Lankan', '94'],
            [211, 729, 'SD', 'SDN', 'Sudan', 'Sudanese', 'Sudanese', '249'],
            [212, 740, 'SR', 'SUR', 'Suriname', 'Surinamese', 'Surinamese', '597'],
            [213, 744, 'SJ', 'SJM', 'Svalbard and Jan Mayen', 'Svalbard', 'Svalbard Islands', '47'],
            [214, 748, 'SZ', 'SWZ', 'Swaziland', 'Swazi', 'Swazi', '268'],
            [215, 752, 'SE', 'SWE', 'Sweden', 'Swedish', 'Swedish', '46'],
            [216, 756, 'CH', 'CHE', 'Switzerland', 'Swiss', 'Swiss', '41'],
            [217, 760, 'SY', 'SYR', 'Syrian Arab Republic', 'Syrian', 'Syrian', '963'],
            [218, 158, 'TW', 'TWN', 'Taiwan, Province of China', 'Chinese, Taiwanese', 'Taiwanese', '886'],
            [219, 762, 'TJ', 'TJK', 'Tajikistan', 'Tajikistani', 'Tajikistani', '992'],
            [220, 834, 'TZ', 'TZA', 'Tanzania, United Republic of', 'Tanzanian', 'Tanzanian', '255'],
            [221, 764, 'TH', 'THA', 'Thailand', 'Thai', 'Thai', '66'],
            [222, 626, 'TL', 'TLS', 'Timor-Leste', 'Timorese', 'Timorese', '670'],
            [223, 768, 'TG', 'TGO', 'Togo', 'Togolese', 'Togolese', '228'],
            [224, 772, 'TK', 'TKL', 'Tokelau', 'Tokelauan', 'Tokelau', '690'],
            [225, 776, 'TO', 'TON', 'Tonga', 'Tongan', 'Tongan', '676'],
            [226, 780, 'TT', 'TTO', 'Trinidad and Tobago', 'Trinidadian, Tobagonian', 'Trinidadian', '1868'],
            [227, 788, 'TN', 'TUN', 'Tunisia', 'Tunisian', 'Tunisian', '216'],
            [228, 792, 'TR', 'TUR', 'Turkey', 'Turkish', 'Turkish', '90'],
            [229, 795, 'TM', 'TKM', 'Turkmenistan', 'Turkmen', 'Turkmen', '993'],
            [230, 796, 'TC', 'TCA', 'Turks and Caicos Islands', 'Turks and Caicos Island', 'Turks And Caicos Islands', '1649'],
            [231, 798, 'TV', 'TUV', 'Tuvalu', 'Tuvaluan', 'Tuvaluan', '688'],
            [232, 800, 'UG', 'UGA', 'Uganda', 'Ugandan', 'Ugandan', '256'],
            [233, 804, 'UA', 'UKR', 'Ukraine', 'Ukrainian', 'Ukrainian', '380'],
            [234, 784, 'AE', 'ARE', 'United Arab Emirates', 'Emirati, Emirian, Emiri', 'Emirati', '971'],
            [235, 826, 'GB', 'GBR', 'United Kingdom of Great Britain and Northern Ireland', 'British, UK', 'British', '44'],
            [236, 581, 'UM', 'UMI', 'United States Minor Outlying Islands', 'American', 'American', '1'],
            [237, 840, 'US', 'USA', 'United States of America', 'American', 'American', '1'],
            [238, 858, 'UY', 'URY', 'Uruguay', 'Uruguayan', 'Uruguayan', '598'],
            [239, 860, 'UZ', 'UZB', 'Uzbekistan', 'Uzbekistani, Uzbek', 'Uzbek', '998'],
            [240, 548, 'VU', 'VUT', 'Vanuatu', 'Ni-Vanuatu, Vanuatuan', 'Ni-Vanuatu', '678'],
            [241, 862, 'VE', 'VEN', 'Venezuela (Bolivarian Republic of)', 'Venezuelan', 'Venezuelan', '58'],
            [242, 704, 'VN', 'VNM', 'Vietnam', 'Vietnamese', 'Vietnamese', '84'],
            [243, 92, 'VG', 'VGB', 'Virgin Islands (British)', 'British Virgin Island', 'British Virgin Islanderas', '1284'],
            [244, 850, 'VI', 'VIR', 'Virgin Islands (U.S.)', 'U.S. Virgin Island', 'United States Virgin Islands', '1340'],
            [245, 876, 'WF', 'WLF', 'Wallis and Futuna', 'Wallis and Futuna, Wallisian, Futunan', 'Wallis And Futuna', '681'],
            [246, 732, 'EH', 'ESH', 'Western Sahara', 'Sahrawi, Sahrawian, Sahraouian', 'Sahrawian', '212'],
            [247, 887, 'YE', 'YEM', 'Yemen', 'Yemeni', 'Yemeni', '967'],
            [248, 894, 'ZM', 'ZMB', 'Zambia', 'Zambian', 'Zambian', '260'],
            [249, 716, 'ZW', 'ZWE', 'Zimbabwe', 'Zimbabwean', 'Zimbabwean', '263'],
    ];

    public function run(): void
    {
        $now = now();

        $countries = [];
        foreach (self::COUNTRIES as [$id, $num, $a2, $a3, $name, $legacyNationality, $shafafiya, $phone]) {
            $countries[] = [
                'id' => $id,
                'num_code' => $num ?: null,
                'alpha_2_code' => $a2,
                'alpha_3_code' => $a3,
                'en_short_name' => $name,
                // The canonical demonym: the first element of the legacy list.
                'nationality' => $this->canonicalNationality($legacyNationality),
                'shafafiya_nationality_code' => $shafafiya,
                'phone_code' => $phone,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($countries, 200) as $chunk) {
            DB::table('countries')->upsert(
                $chunk,
                ['id'],
                ['num_code', 'alpha_2_code', 'alpha_3_code', 'en_short_name',
                    'nationality', 'shafafiya_nationality_code', 'phone_code', 'updated_at']
            );
        }

        $this->seedAliases($now);
    }

    /**
     * Build one alias row per spelling, strongest source first, and let the
     * first claim on a normalised spelling win. That is why this sorts by
     * precedence before inserting rather than making a single pass: "Indian"
     * must be offered by India (as nationality) before the British Indian Ocean
     * Territory offers it (as shafafiya), or the wrong country takes 527
     * patients with it.
     */
    private function seedAliases(mixed $now): void
    {
        $candidates = [];

        foreach (self::MANUAL_ALIASES as $alias => $countryId) {
            $candidates[] = [$alias, $countryId, 'manual'];
        }

        foreach (self::COUNTRIES as [$id, , , , $name, $legacyNationality, $shafafiya]) {
            $legacyNationality = trim((string) $legacyNationality);

            if ($legacyNationality !== '') {
                // The whole legacy string, exactly as patient rows store it
                // ("Emirati, Emirian, Emiri").
                $candidates[] = [$legacyNationality, $id, 'nationality'];

                // ...and each element of it ("Emirati", "Emirian", "Emiri").
                foreach (explode(',', $legacyNationality) as $part) {
                    if (($part = trim($part)) !== '') {
                        $candidates[] = [$part, $id, 'nationality_part'];
                    }
                }
            }

            if (($name = trim((string) $name)) !== '') {
                $candidates[] = [$name, $id, 'en_short_name'];
            }

            if (($shafafiya = trim((string) $shafafiya)) !== '') {
                $candidates[] = [$shafafiya, $id, 'shafafiya'];
            }
        }

        usort($candidates, fn ($a, $b) => self::SOURCE_PRECEDENCE[$a[2]] <=> self::SOURCE_PRECEDENCE[$b[2]]);

        $seen = [];
        $rows = [];
        foreach ($candidates as [$alias, $countryId, $source]) {
            $normalised = self::normalise($alias);

            if ($normalised === '' || isset($seen[$normalised])) {
                continue;
            }

            $seen[$normalised] = true;
            $rows[] = [
                'country_id' => $countryId,
                'alias' => $alias,
                'alias_normalised' => $normalised,
                'source' => $source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('country_nationality_aliases')->upsert(
                $chunk, ['alias_normalised'], ['country_id', 'alias', 'source', 'updated_at']
            );
        }
    }

    private function canonicalNationality(?string $legacyNationality): ?string
    {
        $first = trim((string) explode(',', (string) $legacyNationality)[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * Lowercase, strip everything that is not a letter or a digit, collapse
     * spacing. "  Emirati" and "Emirati" and "EMIRATI" all become "emirati",
     * which is what makes matching immune to the stray whitespace and casing in
     * the legacy data.
     */
    public static function normalise(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
