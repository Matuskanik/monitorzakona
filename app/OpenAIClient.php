<?php

namespace App;

class OpenAIClient
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private Logger $logger;

    public function __construct(string $apiKey, string $model, int $maxTokens, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->logger = $logger;
    }

    public function generateSummary(string $text): array
    {
        $this->logger->info("Generating AI summary (text length: " . strlen($text) . " chars)");

        $prompt = $this->buildPrompt($text);
        
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Si expertný právny analytik a komunikátor, ktorý špecializuje sa na analýzu slovenských zákonov a ich preklad do zrozumiteľného jazyka pre bežných občanov. Tvoja úloha je poskytnúť hĺbkovú, praktickú a užitočnú analýzu, ktorá ľuďom pomôže pochopiť, ako ich zákon ovplyvní a čo môžu konkrétne urobiť. Vždy odpovedáš v JSON formáte podľa presnej schémy, pričom každá sekcia musí byť detailná, konkrétna a prakticky použiteľná.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => $this->maxTokens,
                'response_format' => ['type' => 'json_object']
            ]),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API request failed: {$error}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("OpenAI API returned HTTP {$httpCode}: {$response}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }

        $content = $data['choices'][0]['message']['content'];
        $summary = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Failed to parse JSON response: " . json_last_error_msg());
            throw new \RuntimeException("Invalid JSON in AI response");
        }

        // Validate schema
        $this->validateSchema($summary);

        $this->logger->info("AI summary generated successfully");
        return $summary;
    }

    private function buildPrompt(string $text): string
    {
        return "Analyzuj nasledujúci text zo slovenského zákona a vytvor podrobný, kvalitný JSON objekt s týmito presnými kľúčmi:

{
  \"tags\": [\"ekonomika\", \"financie\"],
  \"summary_paragraph\": \"Detailné, viacodsekové zhrnutie zákona (minimálne 3-5 viet, ideálne 150-300 slov). Vysvetli: čo zákon mení, prečo to môže byť dôležité, aké sú kľúčové body, ktoré by ľudia mali vedieť. Používaj konkrétne príklady a situácie, kde je to možné. Píš živým, zrozumiteľným jazykom, ale zachovávaj presnosť.\",
  \"affected_groups\": [\"konkrétna skupina 1 s vysvetlením ako ich to ovplyvní\", \"konkrétna skupina 2 s vysvetlením ako ich to ovplyvní\"],
  \"positives\": [\"konkrétne pozitívum 1 s vysvetlením prečo je to pozitívne\", \"konkrétne pozitívum 2 s vysvetlením\"],
  \"negatives\": [\"konkrétne negatívum 1 s vysvetlením akých dôsledkov sa to týka\", \"konkrétne negatívum 2 s vysvetlením\"],
  \"how_to_react\": [\"konkrétna, akčná rada 1 - čo presne má človek urobiť, kedy, ako\", \"konkrétna, akčná rada 2 - krok za krokom\", \"konkrétna, akčná rada 3 - praktický tip\"],
  \"disclaimer\": \"Toto nie je právne poradenstvo. Informácie sú len informatívneho charakteru. Pre právne poradenstvo sa obráťte na kvalifikovaného právnika.\"
}

DETALNÉ INŠTRUKCIE PRE KAŽDÚ SEKCIU:

**tags:**
- Identifikuj 1-5 kľúčových oblastí, ku ktorým zákon patrí
- Používaj presné, krátke tagy (max. 2 slová, ideálne 1 slovo)
- Možné tagy: \"ekonomika\", \"financie\", \"podnikanie\", \"školstvo\", \"zdravotníctvo\", \"sociálna politika\", \"doprava\", \"bývanie\", \"práca\", \"dane\", \"polícia\", \"justícia\", \"životné prostredie\", \"poľnohospodárstvo\", \"kultúra\", \"šport\", \"turizmus\", \"energetika\", \"IT\", \"telekomunikácie\", \"obrana\", \"verejná správa\", \"miestna samospráva\", \"rodina\", \"mládež\", \"seniori\", \"šport\", \"vzdelávanie\"
- Vyber len relevantné tagy - ak zákon patrí do viacerých oblastí, použij ich všetky
- Tagy musia byť v slovenčine, malými písmenami, bez diakritiky alebo s diakritikou (konzistentne)

**summary_paragraph:**
- Napíš podrobné, viacodsekové zhrnutie (minimálne 150 slov, ideálne 200-300 slov)
- Začni kontextom: čo sa mení a prečo
- Vysvetli kľúčové zmeny konkrétne, nie abstraktne
- Uveď príklady situácií, kde je to relevantné
- Spomeň časové aspekty (kedy to nadobudne platnosť, aké sú prechodné obdobia)
- Ak sú dôležité čísla, sumy, limity - uveď ich
- Používaj živý, zrozumiteľný jazyk, ale buď presný

**affected_groups:**
- Identifikuj konkrétne skupiny ľudí (nie len \"občania\", ale napr. \"zamestnanci v malých firmách do 10 zamestnancov\", \"študenti vysokých škôl\", \"dôchodcovia nad 65 rokov\")
- Pre každú skupinu stručne vysvetli, ako ich to konkrétne ovplyvní (nie len \"sú ovplyvnení\", ale \"budú musieť...\", \"stratia možnosť...\", \"získajú právo...\")
- Minimálne 3-5 konkrétnych skupín, ak je to možné

**positives:**
- Identifikuj skutočné výhody a pozitíva, nie len formálne
- Pre každé pozitívum vysvetli, prečo je to výhoda a pre koho konkrétne
- Ak sú to len teoretické výhody, ktoré v praxi nefungujú, spomeň to
- Buď realistický - nie každý zákon má len pozitíva

**negatives:**
- Identifikuj skutočné problémy, riziká a nevýhody
- Pre každé negatívum vysvetli, aké konkrétne dôsledky to môže mať
- Spomeň, kto konkrétne môže byť negatívne ovplyvnený
- Buď konštruktívny, ale aj uprimný

**how_to_react:**
- Toto je NAJDÔLEŽITEJŠIA sekcia - musí obsahovať konkrétne, akčné rady
- Každá rada musí byť praktická a použiteľná (nie len \"prečítajte si zákon\")
- Uveď konkrétne kroky: čo presne má človek urobiť, kedy, kde, ako
- Ak sú lehoty, termíny - uveď ich
- Ak sú potrebné dokumenty, formuláre - spomeň ich
- Ak sú kontakty, inštitúcie - uveď ich
- Ak sú možnosti odvolania, námietok - vysvetli proces
- Minimálne 3-5 konkrétnych, akčných rád
- Príklady dobrých rád: \"Do 30 dní od nadobudnutia platnosti zákona pošlite žiadosť na [inštitúcia] na adresu [adresa] s prílohou [dokumenty]. Formulár nájdete na [URL].\"
- Príklady zlých rád (NEPOUŽÍVAŤ): \"Prečítajte si zákon\", \"Kontaktujte právnika\", \"Buďte opatrní\"

VŠEOBECNÉ PRAVIDLÁ:
- Používaj LEN informácie z poskytnutého textu. Ak niečo nie je v texte, napíš \"Informácie o tomto aspekte nie sú v poskytnutom texte dostupné.\"
- Buď zrozumiteľný pre bežných ľudí bez právnického vzdelania
- Vyhýbaj sa halucináciám. Ak si nie si istý, povedz to
- Používaj opatrnú reč tam, kde je to vhodné (\"môže ovplyvniť\", \"pravdepodobne\", \"podľa textu zákona\")
- Pre \"how_to_react\": LEN legálne, súladné, praktické návrhy. Žiadne nelegálne rady, daňové úniky alebo \"exploity\"
- Všetky texty musia byť v slovenčine
- Odpovedaj VÝLUČNE v JSON formáte bez akýchkoľvek dodatočných komentárov
- Kvalita je dôležitejšia ako kvantita - lepšie menej, ale kvalitných bodov, než veľa povrchných

Text zákona:
" . substr($text, 0, 30000); // Limit to avoid token limits
    }

    private function validateSchema(array $data): void
    {
        $required = ['tags', 'summary_paragraph', 'affected_groups', 'positives', 'negatives', 'how_to_react', 'disclaimer'];
        
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException("Missing required key in AI response: {$key}");
            }
        }

        if (!is_string($data['summary_paragraph']) || !is_string($data['disclaimer'])) {
            throw new \RuntimeException("summary_paragraph and disclaimer must be strings");
        }

        $arrayKeys = ['tags', 'affected_groups', 'positives', 'negatives', 'how_to_react'];
        foreach ($arrayKeys as $key) {
            if (!is_array($data[$key])) {
                throw new \RuntimeException("{$key} must be an array");
            }
        }
        
        // Validate tags
        if (empty($data['tags'])) {
            throw new \RuntimeException("tags array must not be empty");
        }
    }
    
    public static function getTagColor(string $tag): string
    {
        // Taxónomia farieb pre tagy
        $colorMap = [
            // Ekonomika a financie
            'ekonomika' => '#3498db',      // Modrá
            'financie' => '#2ecc71',       // Zelená
            'dane' => '#e74c3c',           // Červená
            'podnikanie' => '#f39c12',     // Oranžová
            
            // Školstvo a vzdelávanie
            'školstvo' => '#9b59b6',       // Fialová
            'vzdelávanie' => '#9b59b6',   // Fialová
            
            // Zdravotníctvo
            'zdravotníctvo' => '#e91e63',  // Ružová
            'zdravie' => '#e91e63',        // Ružová
            
            // Sociálna politika
            'sociálna politika' => '#00bcd4', // Cyan
            'sociálne' => '#00bcd4',       // Cyan
            'rodina' => '#ff9800',         // Tmavá oranžová
            'mládež' => '#4caf50',         // Svetlá zelená
            'seniori' => '#795548',        // Hnedá
            
            // Doprava a infraštruktúra
            'doprava' => '#607d8b',        // Modrošedá
            'bývanie' => '#8bc34a',        // Svetlá zelená
            
            // Práca a zamestnanie
            'práca' => '#ff5722',          // Tmavá oranžová
            'zamestnanie' => '#ff5722',    // Tmavá oranžová
            
            // Bezpečnosť a právo
            'polícia' => '#3f51b5',        // Indigo
            'justícia' => '#673ab7',       // Hluboká fialová
            'obrana' => '#212121',         // Tmavá šedá
            
            // Životné prostredie
            'životné prostredie' => '#4caf50', // Zelená
            'ekológia' => '#4caf50',      // Zelená
            
            // Poľnohospodárstvo
            'poľnohospodárstvo' => '#8bc34a', // Svetlá zelená
            'poľnohospodárstvo' => '#8bc34a', // Svetlá zelená
            
            // Kultúra a šport
            'kultúra' => '#e91e63',       // Ružová
            'šport' => '#ff9800',          // Oranžová
            'turizmus' => '#00acc1',       // Cyan
            
            // Energetika a IT
            'energetika' => '#ffc107',     // Žltá
            'IT' => '#2196f3',            // Svetlá modrá
            'telekomunikácie' => '#2196f3', // Svetlá modrá
            
            // Verejná správa
            'verejná správa' => '#795548', // Hnedá
            'miestna samospráva' => '#607d8b', // Modrošedá
        ];
        
        // Normalize tag (lowercase, remove accents for matching)
        $normalized = mb_strtolower($tag, 'UTF-8');
        
        // Try exact match first
        if (isset($colorMap[$normalized])) {
            return $colorMap[$normalized];
        }
        
        // Try partial match
        foreach ($colorMap as $key => $color) {
            if (strpos($normalized, $key) !== false || strpos($key, $normalized) !== false) {
                return $color;
            }
        }
        
        // Default color if no match (generate from hash)
        $hash = md5($normalized);
        $colors = ['#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6', '#e91e63', '#00bcd4', '#ff9800'];
        return $colors[hexdec(substr($hash, 0, 1)) % count($colors)];
    }
}


