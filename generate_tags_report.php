<?php

use App\Models\Shop;
use App\Services\ShopifyClient;

// Boot Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Parse command line arguments
$targetDomain = $argv[1] ?? 'the-social-life-2.myshopify.com';

// Clean the domain input
$cleanDomain = trim(strtolower($targetDomain));
$cleanDomain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $cleanDomain);
$cleanDomain = rtrim($cleanDomain, '/');

// Parse flags
$isLocal = in_array('--local', $argv);
$isDebug = in_array('--debug', $argv);

$isSocialLife = str_contains(strtolower($cleanDomain), 'the-social-life');

try {
    $shop = Shop::where('shop_domain', 'like', "%{$cleanDomain}%")->first();
} catch (\Exception $e) {
    if ($isLocal) {
        $shop = new stdClass();
        $shop->shop_domain = $cleanDomain;
        $shop->id = 0;
    } else {
        echo "Error: Database connection failed. " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (!$shop) {
    echo "Error: Shop with domain matching '{$targetDomain}' (cleaned: '{$cleanDomain}') not found in database.\n";
    exit(1);
}

echo "Shop: {$shop->shop_domain} (ID: {$shop->id})\n";

// Define Licensor Lookup Array
$licensorRules = [
    [
        'name' => 'Pi Beta Phi',
        'full_names' => ['Pi Beta Phi'],
        'abbreviations' => ['Pi Phi', 'PBP']
    ],
    [
        'name' => 'Delta Gamma',
        'full_names' => ['Delta Gamma'],
        'abbreviations' => ['DG']
    ],
    [
        'name' => 'Alpha Phi',
        'full_names' => ['Alpha Phi'],
        'abbreviations' => ['APHI', 'AP', 'AlphaPhi']
    ],
    [
        'name' => 'Alpha Chi Omega',
        'full_names' => ['Alpha Chi Omega'],
        'abbreviations' => ['AXO', 'Alpha Chi']
    ],
    [
        'name' => 'Kappa Kappa Gamma',
        'full_names' => ['Kappa Kappa Gamma'],
        'abbreviations' => ['KKG']
    ],
    [
        'name' => 'Zeta Tau Alpha',
        'full_names' => ['Zeta Tau Alpha'],
        'abbreviations' => ['ZTA']
    ],
    [
        'name' => 'Alpha Delta Pi',
        'full_names' => ['Alpha Delta Pi'],
        'abbreviations' => ['ADPI']
    ],
    [
        'name' => 'Alpha Gamma Delta',
        'full_names' => ['Alpha Gamma Delta'],
        'abbreviations' => ['AGD']
    ],
    [
        'name' => 'Alpha Sigma Tau',
        'full_names' => ['Alpha Sigma Tau'],
        'abbreviations' => ['AST']
    ],
    [
        'name' => 'Alpha Epsilon Phi',
        'full_names' => ['Alpha Epsilon Phi'],
        'abbreviations' => ['AEPHI']
    ],
    [
        'name' => 'Alpha Omicron Pi',
        'full_names' => ['Alpha Omicron Pi'],
        'abbreviations' => ['AOII', 'AOTT', 'AOPi']
    ],
    [
        'name' => 'Alpha Xi Delta',
        'full_names' => ['Alpha Xi Delta'],
        'abbreviations' => ['AXID', 'AZD']
    ],
    [
        'name' => 'Chi Omega',
        'full_names' => ['Chi Omega'],
        'abbreviations' => ['CHIO', 'Chi O']
    ],
    [
        'name' => 'Delta Phi Epsilon',
        'full_names' => ['Delta Phi Epsilon'],
        'abbreviations' => ['DPHIE']
    ],
    [
        'name' => 'Delta Zeta',
        'full_names' => ['Delta Zeta'],
        'abbreviations' => ['DZ']
    ],
    [
        'name' => 'Gamma Phi Beta',
        'full_names' => ['Gamma Phi Beta'],
        'abbreviations' => ['GPHI', 'GPB', 'Gamma Phi', 'G Phi']
    ],
    [
        'name' => 'Kappa Delta',
        'full_names' => ['Kappa Delta'],
        'abbreviations' => ['KD']
    ],
    [
        'name' => 'Kappa Alpha Theta',
        'full_names' => ['Kappa Alpha Theta'],
        'abbreviations' => ['KAT', 'THETA', 'KAO']
    ],
    [
        'name' => 'Phi Mu',
        'full_names' => ['Phi Mu'],
        'abbreviations' => ['PHIMU']
    ],
    [
        'name' => 'Sigma Kappa',
        'full_names' => ['Sigma Kappa'],
        'abbreviations' => ['SK']
    ],
    [
        'name' => 'Sigma Delta Tau',
        'full_names' => ['Sigma Delta Tau'],
        'abbreviations' => ['SDT', 'SIG DELT']
    ],
    [
        'name' => 'Sigma Sigma Sigma',
        'full_names' => ['Sigma Sigma Sigma'],
        'abbreviations' => ['SSS', 'TRI SIGMA', 'TRI SIG']
    ],
    [
        'name' => 'Theta Phi Alpha',
        'full_names' => ['Theta Phi Alpha'],
        'abbreviations' => ['TPA', 'Theta Phi']
    ],
    [
        'name' => 'Alpha Sigma Alpha',
        'full_names' => ['Alpha Sigma Alpha'],
        'abbreviations' => ['ASA', 'ASAE']
    ],
    [
        'name' => 'Pi Kappa Phi',
        'full_names' => ['Pi Kappa Phi'],
        'abbreviations' => ['PIKAPP', 'PKP']
    ],
    [
        'name' => 'Delta Tau Delta',
        'full_names' => ['Delta Tau Delta'],
        'abbreviations' => ['DTD', 'Delts']
    ],
    [
        'name' => 'Phi Gamma Delta',
        'full_names' => ['Phi Gamma Delta'],
        'abbreviations' => ['FIJI']
    ],
    [
        'name' => 'Sigma Alpha Epsilon',
        'full_names' => ['Sigma Alpha Epsilon'],
        'abbreviations' => ['SAE']
    ],
    [
        'name' => 'Pi Kappa Alpha',
        'full_names' => ['Pi Kappa Alpha'],
        'abbreviations' => ['PIKE']
    ],
    [
        'name' => 'Kappa Alpha Order',
        'full_names' => ['Kappa Alpha Order'],
        'abbreviations' => ['KA']
    ],
    [
        'name' => 'Tau Kappa Epsilon',
        'full_names' => ['Tau Kappa Epsilon'],
        'abbreviations' => ['TKE']
    ],
    [
        'name' => 'Alpha Tau Omega',
        'full_names' => ['Alpha Tau Omega'],
        'abbreviations' => ['ATO']
    ],
    [
        'name' => 'Phi Delta Theta',
        'full_names' => ['Phi Delta Theta'],
        'abbreviations' => ['PDT']
    ],
    [
        'name' => 'Phi Delta Epsilon',
        'full_names' => ['Phi Delta Epsilon'],
        'abbreviations' => ['PDE']
    ],
    [
        'name' => 'National Charity League',
        'full_names' => ['National Charity League'],
        'abbreviations' => ['NCL']
    ],
    // Additional ones from catalog:
    [
        'name' => 'Delta Delta Delta',
        'full_names' => ['Delta Delta Delta'],
        'abbreviations' => ['TriDelt', 'Tri Delt', 'DDD', 'Tri Delta']
    ],
    [
        'name' => 'Delta Sigma Phi',
        'full_names' => ['Delta Sigma Phi'],
        'abbreviations' => ['Delta Sig', 'DSPhi', 'DSP']
    ],
    [
        'name' => 'Phi Sigma Sigma',
        'full_names' => ['Phi Sigma Sigma'],
        'abbreviations' => ['Phi Sig', 'PSS']
    ],
    [
        'name' => 'Lambda Chi Alpha',
        'full_names' => ['Lambda Chi Alpha'],
        'abbreviations' => ['Lambda Chi', 'Lambda', 'LCA']
    ],
    [
        'name' => 'Sigma Chi',
        'full_names' => ['Sigma Chi'],
        'abbreviations' => []
    ],
    [
        'name' => 'Sigma Phi Epsilon',
        'full_names' => ['Sigma Phi Epsilon'],
        'abbreviations' => ['SigEp', 'SIG EP']
    ],
    [
        'name' => 'Theta Chi',
        'full_names' => ['Theta Chi'],
        'abbreviations' => []
    ],
    [
        'name' => 'Alpha Epsilon Pi',
        'full_names' => ['Alpha Epsilon Pi'],
        'abbreviations' => ['AEPi']
    ],
    [
        'name' => 'Kappa Sigma',
        'full_names' => ['Kappa Sigma'],
        'abbreviations' => ['Kappa Sig']
    ],
    [
        'name' => 'Chi Phi',
        'full_names' => ['Chi Phi'],
        'abbreviations' => []
    ],
    [
        'name' => 'Alpha Kappa Delta Phi',
        'full_names' => ['Alpha Kappa Delta Phi'],
        'abbreviations' => ['AKDPHI']
    ],
    [
        'name' => 'Sigma Alpha',
        'full_names' => ['Sigma Alpha'],
        'abbreviations' => []
    ],
    [
        'name' => 'Delta Chi',
        'full_names' => ['Delta Chi'],
        'abbreviations' => ['DChi']
    ],
    [
        'name' => 'Sigma Nu',
        'full_names' => ['Sigma Nu'],
        'abbreviations' => ['SN']
    ],
    [
        'name' => 'Alpha Sigma Phi',
        'full_names' => ['Alpha Sigma Phi'],
        'abbreviations' => ['Alpha Sig']
    ],
    [
        'name' => 'Delta Sigma Pi',
        'full_names' => ['Delta Sigma Pi'],
        'abbreviations' => ['DSP']
    ],
    [
        'name' => 'Phi Sigma Rho',
        'full_names' => ['Phi Sigma Rho'],
        'abbreviations' => ['Phi Rho', 'PSR']
    ],
    [
        'name' => 'Beta Theta Pi',
        'full_names' => ['Beta Theta Pi'],
        'abbreviations' => ['Beta', 'BTP']
    ]
];

// Define Non-Licensor Vendors
$nonLicensorVendors = [
    'through6', 'fuel', 'the social life', 'zephyr hats', 'eyeblack', "big's", "g big's", "little's",
    'cal poly san luis obispo', 'greek week', 'test vendor', 'medmask supply co', 'arizona state university',
    'florida state university', 'acme', 'one tree planted', 'arizona, university of', 'alabama, university of',
    'university of california, santa barbara', 'san diego state university', 'michigan state university',
    'florida, university of', 'oregon, university of', 'pennsylvania state university', 'south carolina, university of',
    'miami, university of', 'fresno state', 'virginia tech', 'princeton crew'
];

$defaultLicensor = getStoreDefaultLicensor($shop->shop_domain);

$products = [];

if ($isLocal) {
    // OFFLINE MODE: Load products directly from local CSV file
    $safeDomain = str_replace([':', '/'], '_', $shop->shop_domain);
    $filename = "{$safeDomain}_products_report.csv";
    
    if (!file_exists($filename)) {
        echo "Error: Local CSV file '{$filename}' not found. Please fetch from Shopify first or place the file in the project root.\n";
        exit(1);
    }
    
    echo "Offline Mode: Reading products from local CSV file '{$filename}'...\n";
    
    if (($handle = fopen($filename, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        // Create lookup array for headers
        $headerMap = array_flip($headers);
        
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            $tagsVal = $data[$headerMap['Product Tags'] ?? 4] ?? '';
            $tagsArray = array_map('trim', explode(',', $tagsVal));
            
            $products[] = [
                'id' => 'gid://shopify/Product/' . ($data[$headerMap['Product ID'] ?? 0] ?? ''),
                'title' => $data[$headerMap['Product Title'] ?? 1] ?? '',
                'productType' => $data[$headerMap['Product Type'] ?? 2] ?? '',
                'vendor' => $data[$headerMap['Vendor'] ?? 3] ?? '',
                'tags' => $tagsArray
            ];
        }
        fclose($handle);
    }
} else {
    // ONLINE MODE: Fetch products via Shopify GraphQL API
    $client = new ShopifyClient($shop);
    $cursor = null;
    $hasNextPage = true;
    $page = 1;

    echo "Online Mode: Fetching products from Shopify via paginated GraphQL requests...\n";

    try {
        while ($hasNextPage) {
            echo "Fetching page {$page}...\n";
            
            $query = <<<GQL
query (\$cursor: String) {
  products(first: 250, after: \$cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    edges {
      node {
        id
        title
        productType
        vendor
        tags
      }
    }
  }
}
GQL;

            $variables = ['cursor' => $cursor];
            $result = $client->graph($query, $variables);
            
            if (!isset($result['data']['products'])) {
                echo "Error: Invalid response structure from Shopify API.\n";
                if (isset($result['errors'])) {
                    echo "GraphQL Errors: " . json_encode($result['errors'], JSON_PRETTY_PRINT) . "\n";
                }
                exit(1);
            }
            
            $edges = $result['data']['products']['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                if (!empty($node)) {
                    $products[] = $node;
                }
            }
            
            $pageInfo = $result['data']['products']['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;
            
            echo "Retrieved " . count($edges) . " products in this page. Total collected: " . count($products) . "\n";
            $page++;
            
            usleep(200000); // 200ms
        }
    } catch (\Exception $e) {
        echo "Error during API fetch: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if (empty($products)) {
    echo "No products found to process.\n";
    exit(0);
}

// Prepare report fields
$safeDomain = str_replace([':', '/'], '_', $shop->shop_domain);
$outputFilename = "{$safeDomain}_products_report.csv";
$licensingExportFilename = "{$safeDomain}_licensing_export.csv";

// CSV headers
$csvContent = "Product ID,Product Title,Product Type,Vendor,Product Tags,Primary Licensor\n";
$licensingExportContent = "Store Name,Product Name,UPI,Product Category,Product Main Image URL,Primary Licensor\n";

$debugFilename = "licensor_debug.csv";
$exceptionsFilename = "licensor_exceptions.csv";
$missingLookupFilename = "missing_lookup.csv";

$debugCsv = "\"Product ID\",\"Title\",\"Tags\",\"Matched Keywords\",\"Matched Licensors\",\"Winning Licensor\",\"Winning Score\",\"Reason\"\n";
$exceptionsCsv = "\"Product ID\",\"Title\",\"Tags\",\"Matched Keywords\",\"Matched Licensors\",\"Winning Licensor\",\"Winning Score\",\"Reason\"\n";
$missingLookupCsv = "\"Keyword Found\",\"Title\",\"Tags\",\"Suggested Licensor\"\n";

// Metrics
$metricTotal = 0;
$metricCorrect = 0;
$metricIncorrect = 0;
$metricMissing = 0;
$metricMultipleMatches = 0;
$metricWithoutTags = 0;
$metricWithoutDetectable = 0;
$metricMatchedByTitle = 0;
$metricMatchedByTags = 0;

$unknownLicensors = [];

$greekWords = ["ALPHA", "BETA", "GAMMA", "DELTA", "EPSILON", "ZETA", "ETA", "THETA", "IOTA", "KAPPA", "LAMBDA", "MU", "NU", "XI", "OMICRON", "PI", "RHO", "SIGMA", "TAU", "UPSILON", "PHI", "CHI", "PSI", "OMEGA"];

foreach ($products as $node) {
    $id = $node['id'] ?? '';
    $legacyId = preg_replace('/[^0-9]/', '', $id);
    
    $title = $node['title'] ?? '';
    $type = $node['productType'] ?? '';
    $vendor = $node['vendor'] ?? '';
    $tagsArray = $node['tags'] ?? [];
    $tagsString = implode(', ', $tagsArray);
    
    // Determine Primary Licensor:
    // For The Social Life (or stores with Various/empty default): perform product-level detection
    // For all other stores: Primary Licensor is FIXED to the store's primary licensor
    if ($isSocialLife || empty($defaultLicensor) || strtolower($defaultLicensor) === 'various') {
        $match = getBestLicensorMatch($title, $tagsString, $licensorRules);
        $licensor = $match['name'];
    } else {
        $licensor = $defaultLicensor;
        $match = [
            'name' => $defaultLicensor,
            'score' => 100,
            'reason' => 'Store Fixed Licensor',
            'source' => 'Store Fixed Licensor',
            'all_matches' => []
        ];
    }
    
    // Escape values
    $escapedTitle = str_replace('"', '""', $title);
    $escapedType = str_replace('"', '""', $type);
    $escapedVendor = str_replace('"', '""', $vendor);
    $escapedTags = str_replace('"', '""', $tagsString);
    $escapedLicensor = str_replace('"', '""', $licensor);
    
    // Write standard report CSV row (Primary Licensor is never blank unless it's empty, but if no match, it is 'Various')
    $csvContent .= "\"{$legacyId}\",\"{$escapedTitle}\",\"{$escapedType}\",\"{$escapedVendor}\",\"{$escapedTags}\",\"{$escapedLicensor}\"\n";
    
    // Write licensing export format row (No tags column, only if licensor is 'Various')
    if ($licensor === 'Various') {
        $escapedStoreName = str_replace('"', '""', $shop->shop_domain);
        $licensingExportContent .= "\"{$escapedStoreName}\",\"{$escapedTitle}\",\"\",\"{$escapedType}\",\"\",\"{$escapedLicensor}\"\n";
    }
    
    // Write debug rows
    $matchedKeywords = [];
    $matchedNames = [];
    foreach ($match['all_matches'] as $m) {
        $matchedKeywords[] = $m['keyword'];
        $matchedNames[] = $m['name'];
    }
    $matchedKeywordsStr = str_replace('"', '""', implode(', ', array_unique($matchedKeywords)));
    $matchedNamesStr = str_replace('"', '""', implode(', ', array_unique($matchedNames)));
    $escapedReason = str_replace('"', '""', $match['reason']);
    
    $debugCsv .= "\"{$legacyId}\",\"{$escapedTitle}\",\"{$escapedTags}\",\"{$matchedKeywordsStr}\",\"{$matchedNamesStr}\",\"{$escapedLicensor}\",\"{$match['score']}\",\"{$escapedReason}\"\n";
    
    // Exceptions logic:
    // - no licensor found (score == 0)
    // - multiple equal matches (Tie in reason)
    // - blank licensor (if empty)
    // - unknown organization (if detected is not Various, but vendor is a known licensor and is different)
    $cleanVendor = trim(preg_replace('/\s*\(private\)\s*/i', '', $vendor));
    $isKnownLicensor = !in_array(strtolower($cleanVendor), $nonLicensorVendors);
    
    $isException = false;
    if ($match['score'] == 0.0) {
        $isException = true;
    } else if (str_contains($match['reason'], 'Tie')) {
        $isException = true;
    } else if (empty($licensor)) {
        $isException = true;
    } else if ($isKnownLicensor && strtolower($licensor) !== strtolower($cleanVendor)) {
        $isException = true;
    }
    
    if ($isException) {
        $exceptionsCsv .= "\"{$legacyId}\",\"{$escapedTitle}\",\"{$escapedTags}\",\"{$matchedKeywordsStr}\",\"{$matchedNamesStr}\",\"{$escapedLicensor}\",\"{$match['score']}\",\"{$escapedReason}\"\n";
    }
    
    // Missing lookup logic:
    // If no match found, check if common Greek letters are in title/tags
    if ($match['score'] == 0.0) {
        $normTitle = normalizeText($title);
        $normTags = normalizeText($tagsString);
        $titleWords = explode(' ', $normTitle);
        $tagsWords = explode(' ', $normTags);
        $allWords = array_unique(array_merge($titleWords, $tagsWords));
        
        $foundGreeks = [];
        foreach ($allWords as $word) {
            if (in_array($word, $greekWords)) {
                $foundGreeks[] = $word;
            }
        }
        if (!empty($foundGreeks)) {
            $missingLookupCsv .= "\"" . implode(', ', $foundGreeks) . "\",\"" . $escapedTitle . "\",\"" . $escapedTags . "\",\"Various\"\n";
        }
    }
    
    // Metric Calculations
    $metricTotal++;
    
    if ($isKnownLicensor) {
        $target = $cleanVendor;
    } else {
        $target = $licensor;
    }
    
    if ($licensor === 'Various') {
        $metricMissing++;
    } else if (strtolower($licensor) === strtolower($target)) {
        $metricCorrect++;
    } else {
        $metricIncorrect++;
        $unknownLicensors[] = $cleanVendor;
    }
    
    if (count($match['all_matches']) > 1) {
        $metricMultipleMatches++;
    }
    if (empty(trim($tagsString))) {
        $metricWithoutTags++;
    }
    if ($licensor === 'Various' && empty($match['all_matches'])) {
        $metricWithoutDetectable++;
    }
    if (str_contains($match['source'], 'Title')) {
        $metricMatchedByTitle++;
    }
    if (str_contains($match['source'], 'Tags')) {
        $metricMatchedByTags++;
    }
}

// Save CSV Files
file_put_contents($outputFilename, $csvContent);
$outputPath = realpath($outputFilename);

file_put_contents($licensingExportFilename, $licensingExportContent);
$licensingExportPath = realpath($licensingExportFilename);

file_put_contents($debugFilename, $debugCsv);
file_put_contents($exceptionsFilename, $exceptionsCsv);
file_put_contents($missingLookupFilename, $missingLookupCsv);

// Print Report
echo "\n======================================================\n";
echo "                   DETECTION REPORT\n";
echo "======================================================\n";
echo "Total Products:                      " . $metricTotal . "\n";
echo "Correct Licensor:                    " . $metricCorrect . "\n";
echo "Incorrect Licensor:                  " . $metricIncorrect . "\n";
echo "Missing Licensor (Various/Blank):    " . $metricMissing . "\n";
echo "Unknown Licensors (Unmapped Vendors): " . count(array_unique($unknownLicensors)) . "\n";
echo "Products with Multiple Matches:      " . $metricMultipleMatches . "\n";
echo "Products without Tags:               " . $metricWithoutTags . "\n";
echo "Products without Detectable Licensor: " . $metricWithoutDetectable . "\n";
echo "Products matched by Title:           " . $metricMatchedByTitle . "\n";
echo "Products matched by Tags:            " . $metricMatchedByTags . "\n";
echo "======================================================\n";
echo "Updated CSV Report: {$outputPath}\n";
echo "Debug CSV Report:   " . realpath($debugFilename) . "\n";
echo "Exceptions Report:  " . realpath($exceptionsFilename) . "\n";
echo "Missing Lookup:     " . realpath($missingLookupFilename) . "\n";
echo "======================================================\n";

/**
 * Normalise strings by converting to uppercase, replacing punctuation with spaces,
 * collapsing spaces and trimming.
 */
function normalizeText(string $text): string {
    $text = strtoupper($text);
    // Normalize punctuation per requirement 4: replace with space
    // characters: - _ / . , & ( ) ' "
    $text = preg_replace('/[\-_.,\/\&()\'"]/', ' ', $text);
    // Collapse multiple spaces
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Get default Primary Licensor by store domain/name mapping
 */
function getStoreDefaultLicensor(string $domain): ?string {
    $domain = strtolower($domain);
    if (str_contains($domain, 'dphie')) return 'Delta Phi Epsilon';
    if (str_contains($domain, 'dzdezigns')) return 'Delta Zeta';
    if (str_contains($domain, 'ast-emerald')) return 'Alpha Sigma Tau';
    if (str_contains($domain, 'penguin')) return 'Theta Phi Alpha';
    if (str_contains($domain, 'phide')) return 'Phi Delta Epsilon';
    if (str_contains($domain, 'hannah')) return 'Delta Gamma';
    if (str_contains($domain, 'delts')) return 'Delta Tau Delta';
    if (str_contains($domain, 'purpleandpearls')) return 'Delta Tau Delta';
    if (str_contains($domain, 'trisigma')) return 'Sigma Sigma Sigma';
    if (str_contains($domain, 'pikapp')) return 'Pi Kappa Phi';
    if (str_contains($domain, 'kappacollection')) return 'Kappa Kappa Gamma';
    if (str_contains($domain, 'ncl')) return 'National Charity League';
    if (str_contains($domain, 'sdt')) return 'Sigma Delta Tau';
    if (str_contains($domain, 'akdphi')) return 'Alpha Kappa Delta Phi';

    return null;
}

/**
 * Score matches and detect the best matching licensor.
 */
function getBestLicensorMatch(string $title, string $tagsString, array $licensorRules): array {
    $normTitle = normalizeText($title);
    $normTags = normalizeText($tagsString);

    $matches = [];

    foreach ($licensorRules as $licensor) {
        $name = $licensor['name'];
        $bestScore = 0;
        $bestKw = "";

        // Check Full Names
        foreach ($licensor['full_names'] as $fn) {
            $fnUpper = normalizeText($fn);
            
            // 1. Exact in Title (100)
            if (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTitle)) {
                if (100 > $bestScore) {
                    $bestScore = 100;
                    $bestKw = $fn;
                }
            }
            // 2. Exact in Tags (90)
            else if (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTags)) {
                if (90 > $bestScore) {
                    $bestScore = 90;
                    $bestKw = $fn;
                }
            }
            // 3. Partial anywhere (60) - Require word boundaries and length >= 4
            else if (strlen($fnUpper) >= 4 && (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTitle) || preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTags))) {
                if (60 > $bestScore) {
                    $bestScore = 60;
                    $bestKw = $fn;
                }
            }
        }

        // Check Abbreviations
        foreach ($licensor['abbreviations'] as $abbr) {
            $abbrUpper = normalizeText($abbr);

            // Exclusions
            if ($abbrUpper === 'CHI OMEGA') {
                $pattern = '/(?<!\bALPHA\s)\bCHI OMEGA\b/i';
            } else if ($abbrUpper === 'THETA') {
                // Exclude Beta Theta Pi (BETA THETA PI)
                $pattern = '/(?<!\bBETA\s)\bTHETA\b(?!\s+(?:PHI\s+ALPHA|PHI|CHI|DELTA\s+CHI|PI))/i';
            } else if ($abbrUpper === 'KA') {
                if (str_contains($normTitle, 'AKDPHI')) {
                    continue;
                }
                $pattern = '/\bKA\b/i';
            } else if ($abbrUpper === 'LAMBDA') {
                if (str_contains($normTitle, 'LAMBDA CHI')) {
                    continue;
                }
                $pattern = '/\bLAMBDA\b/i';
            } else {
                $pattern = '/\b' . preg_quote($abbrUpper, '/') . '\b/i';
            }

            // Exact in Title (80)
            if (preg_match($pattern, $normTitle)) {
                if (80 > $bestScore) {
                    $bestScore = 80;
                    $bestKw = $abbr;
                }
            }
            // Exact in Tags (70)
            else if (preg_match($pattern, $normTags)) {
                if (70 > $bestScore) {
                    $bestScore = 70;
                    $bestKw = $abbr;
                }
            }
            // Partial anywhere (60) - Require word boundaries and length >= 4
            else if (strlen($abbrUpper) >= 4 && (preg_match($pattern, $normTitle) || preg_match($pattern, $normTags))) {
                if (60 > $bestScore) {
                    $bestScore = 60;
                    $bestKw = $abbr;
                }
            }
        }

        if ($bestScore > 0) {
            $matches[] = [
                'name' => $name,
                'score' => $bestScore,
                'keyword' => $bestKw,
                'kw_len' => strlen($bestKw)
            ];
        }
    }

    if (empty($matches)) {
        return [
            'name' => 'Various',
            'score' => 0.0,
            'keyword' => '',
            'source' => 'No Match',
            'reason' => 'No matching keywords found',
            'all_matches' => []
        ];
    }

    // Sort matches: score descending, then kw_len descending
    usort($matches, function ($a, $b) {
        if ($a['score'] !== $b['score']) {
            return ($a['score'] < $b['score']) ? 1 : -1;
        }
        if ($a['kw_len'] !== $b['kw_len']) {
            return ($a['kw_len'] < $b['kw_len']) ? 1 : -1;
        }
        return 0;
    });

    $topScore = $matches[0]['score'];
    $topMatches = array_filter($matches, function ($m) use ($topScore) {
        return $m['score'] === $topScore;
    });

    if (count($topMatches) > 1) {
        // Tie in score: sort by kw_len descending
        usort($topMatches, function ($a, $b) {
            return ($a['kw_len'] < $b['kw_len']) ? 1 : -1;
        });

        $longestLen = $topMatches[0]['kw_len'];
        $topLongest = array_filter($topMatches, function ($m) use ($longestLen) {
            return $m['kw_len'] === $longestLen;
        });

        $uniqueNames = array_unique(array_column($topLongest, 'name'));
        if (count($uniqueNames) > 1) {
            $keywords = [];
            foreach ($topLongest as $tl) {
                $keywords[] = $tl['keyword'];
            }
            return [
                'name' => 'Various',
                'score' => $topScore,
                'keyword' => implode(', ', array_unique($keywords)),
                'source' => 'Tie',
                'reason' => 'Score tie between multiple licensors: ' . implode(', ', $uniqueNames),
                'all_matches' => $matches
            ];
        } else {
            return [
                'name' => $topLongest[0]['name'],
                'score' => $topLongest[0]['score'],
                'keyword' => $topLongest[0]['keyword'],
                'source' => ($topLongest[0]['score'] >= 80) ? 'Title' : 'Tags',
                'reason' => 'Longest keyword wins tie-breaker',
                'all_matches' => $matches
            ];
        }
    }

    $best = $matches[0];
    return [
        'name' => $best['name'],
        'score' => $best['score'],
        'keyword' => $best['keyword'],
        'source' => ($best['score'] >= 80) ? 'Title' : 'Tags',
        'reason' => 'Highest score match',
        'all_matches' => $matches
    ];
}
