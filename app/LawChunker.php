<?php

namespace App;

/**
 * Splits law text into chunks for full-text search and librarian.
 * Prefers semantic boundaries (§, Čl., Hlava); fallback fixed size with overlap.
 */
class LawChunker
{
    private const DEFAULT_CHUNK_SIZE = 2200;
    private const OVERLAP = 200;
    private const SECTION_PATTERN = '#(?:^|\n)\s*(§\s*\d+[a-z]*\.?|Čl\.\s*[IVXLCDM]+\s*\.?|Hlava\s+[IVXLCDM]+|ČASŤ\s+[IVXLCDM]+)#u';

    /**
     * Split text into chunks. Returns array of [content, char_start, char_end, section_title].
     * @return list<array{content: string, char_start: int, char_end: int, section_title: string|null}>
     */
    public static function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $len = mb_strlen($text, 'UTF-8');
        $chunks = [];

        // Try semantic split by § / Čl. / Hlava (use mb_strpos for char offsets)
        $matches = [];
        if (preg_match_all(self::SECTION_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $positions = [];
            foreach ($matches[0] as $m) {
                $bytePos = $m[1];
                $charPos = mb_strlen(mb_substr($text, 0, $bytePos, 'UTF-8'), 'UTF-8');
                $positions[] = [$charPos, trim($m[0])];
            }
            $positions[] = [$len, ''];
            for ($i = 0; $i < count($positions) - 1; $i++) {
                $start = $positions[$i][0];
                $end = $positions[$i + 1][0];
                $content = mb_substr($text, $start, $end - $start, 'UTF-8');
                $content = trim($content);
                if (mb_strlen($content, 'UTF-8') > 100) {
                    $chunks[] = [
                        'content' => $content,
                        'char_start' => $start,
                        'char_end' => $end,
                        'section_title' => $positions[$i][1] ?: null,
                    ];
                }
            }
        }

        // If semantic produced too few or none, use fixed-size chunks
        if (count($chunks) < 2) {
            $chunks = [];
            $pos = 0;
            $idx = 0;
            while ($pos < $len) {
                $chunkLen = min(self::DEFAULT_CHUNK_SIZE, $len - $pos);
                $content = mb_substr($text, $pos, $chunkLen + self::OVERLAP, 'UTF-8');
                $content = trim($content);
                if ($content !== '') {
                    $actualEnd = $pos + mb_strlen($content, 'UTF-8');
                    $chunks[] = [
                        'content' => $content,
                        'char_start' => $pos,
                        'char_end' => $actualEnd,
                        'section_title' => null,
                    ];
                }
                $pos += self::DEFAULT_CHUNK_SIZE;
                $idx++;
            }
        }

        return $chunks;
    }
}
