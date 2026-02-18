<?php

namespace App;

class ParliamentAnalyzer
{
    public function buildVotingSummary(array $voting): string
    {
        $result = $voting['result_text'] ?? ($voting['result'] ?? 'Výsledok neznámy');
        $for = (int)($voting['votes_for'] ?? 0);
        $against = (int)($voting['votes_against'] ?? 0);
        $abstain = (int)($voting['votes_abstain'] ?? 0);
        $absent = (int)($voting['votes_absent'] ?? 0);

        return sprintf(
            'Stručne: %s. Hlasovanie dopadlo "%s" (za: %d, proti: %d, zdržalo sa: %d, neprítomní: %d).',
            $voting['title'] ?? 'Hlasovanie NR SR',
            $result,
            $for,
            $against,
            $abstain,
            $absent
        );
    }

    public function buildMpCard(array $mp): string
    {
        $attendance = (float)($mp['attendance_pct'] ?? 0.0);
        $for = (int)($mp['votes_for'] ?? 0);
        $against = (int)($mp['votes_against'] ?? 0);
        $abstain = (int)($mp['votes_abstain'] ?? 0);
        $total = (int)($mp['total_votes'] ?? 0);

        $attendanceLabel = 'stabilný';
        if ($attendance < 70) {
            $attendanceLabel = 'nízka aktivita';
        } elseif ($attendance > 90) {
            $attendanceLabel = 'veľmi vysoká aktivita';
        }

        $dominant = 'vyvážený';
        $maxValue = max($for, $against, $abstain);
        if ($maxValue > 0) {
            if ($maxValue === $for) {
                $dominant = 'najčastejšie hlasuje ZA';
            } elseif ($maxValue === $against) {
                $dominant = 'najčastejšie hlasuje PROTI';
            } else {
                $dominant = 'častejšie sa zdrží';
            }
        }

        return sprintf(
            'Karta poslanca: %s. Dochádzka %.1f%% (%s), celkovo %d zaznamenaných hlasovaní, profil hlasovania: %s.',
            $mp['full_name'] ?? 'Neznámy poslanec',
            $attendance,
            $attendanceLabel,
            $total,
            $dominant
        );
    }
}
