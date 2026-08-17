<?php

namespace App\Services;

class LicensorDetectionService
{
    public static array $licensorRules = [
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
            'abbreviations' => ['AXO', 'Alpha Chi', 'ACHIO']
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

    public static function normalizeText(string $text): string
    {
        $text = strtoupper($text);
        $text = preg_replace('/[\-_.,\/\&()\'"]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    public static function getBestLicensorMatch(string $title, string $tagsString = ''): string
    {
        $normTitle = self::normalizeText($title);
        $normTags = self::normalizeText($tagsString);

        $matches = [];

        foreach (self::$licensorRules as $licensor) {
            $name = $licensor['name'];
            $bestScore = 0;
            $bestKw = "";

            // Check Full Names
            foreach ($licensor['full_names'] as $fn) {
                $fnUpper = self::normalizeText($fn);
                
                if (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTitle)) {
                    if (100 > $bestScore) {
                        $bestScore = 100;
                        $bestKw = $fn;
                    }
                }
                else if (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTags)) {
                    if (90 > $bestScore) {
                        $bestScore = 90;
                        $bestKw = $fn;
                    }
                }
                else if (strlen($fnUpper) >= 4 && (preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTitle) || preg_match('/\b' . preg_quote($fnUpper, '/') . '\b/i', $normTags))) {
                    if (60 > $bestScore) {
                        $bestScore = 60;
                        $bestKw = $fn;
                    }
                }
            }

            // Check Abbreviations
            foreach ($licensor['abbreviations'] as $abbr) {
                $abbrUpper = self::normalizeText($abbr);

                // Exclusions
                if ($abbrUpper === 'CHI OMEGA') {
                    $pattern = '/(?<!\bALPHA\s)\bCHI OMEGA\b/i';
                } else if ($abbrUpper === 'THETA') {
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

                if (preg_match($pattern, $normTitle)) {
                    if (80 > $bestScore) {
                        $bestScore = 80;
                        $bestKw = $abbr;
                    }
                }
                else if (preg_match($pattern, $normTags)) {
                    if (70 > $bestScore) {
                        $bestScore = 70;
                        $bestKw = $abbr;
                    }
                }
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
            return 'Various';
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
            usort($topMatches, function ($a, $b) {
                return ($a['kw_len'] < $b['kw_len']) ? 1 : -1;
            });

            $longestLen = $topMatches[0]['kw_len'];
            $topLongest = array_filter($topMatches, function ($m) use ($longestLen) {
                return $m['kw_len'] === $longestLen;
            });

            $uniqueNames = array_unique(array_column($topLongest, 'name'));
            if (count($uniqueNames) > 1) {
                return 'Various';
            } else {
                return $topLongest[0]['name'];
            }
        }

        return $matches[0]['name'];
    }

    /**
     * Resolve the Primary Licensor for a given product model instance.
     * For stores other than The Social Life (stores with a fixed licensor), use the fixed store licensor.
     * For The Social Life (stores with Various/empty default), perform product-level detection.
     */
    public static function resolveProductPrimaryLicensor(\App\Models\Product $product): string
    {
        $shop = $product->shop;
        $storeDefault = $shop ? ($shop->primary_licensor ?? '') : '';

        if (!empty($storeDefault) && strtolower($storeDefault) !== 'various') {
            return $storeDefault;
        }

        return self::getBestLicensorMatch($product->title ?? '');
    }
}
