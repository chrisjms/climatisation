<?php
/**
 * inc/tva.php — taux de TVA : référence, validation, formatage, ventilation.
 *
 * Source de vérité unique partagée par le formulaire (devis.php), l'enregistrement
 * (traitement_devis.php) et les trois générateurs PDF (devis, facture, BDC).
 * Toute évolution de taux se fait ICI et se propage partout.
 */

if (!defined('TVA_TAUX_DEFAUT')) {
    define('TVA_TAUX_DEFAUT', 20.0);
}

if (!function_exists('tva_taux_courants')) {
    /**
     * Taux proposés dans les listes déroulantes, du plus fréquent au moins fréquent.
     *
     * Ce n'est PAS une liste blanche fermée : un taux hors liste reste accepté par
     * tva_normalise_taux(), pour absorber un changement de législation sans redéploiement
     * (les taux réduits du bâtiment ont déjà bougé, ils rebougeront).
     */
    function tva_taux_courants(): array {
        return [
            ['taux' => 20.0, 'label' => '20 %',  'aide' => 'Taux normal'],
            ['taux' => 10.0, 'label' => '10 %',  'aide' => "Travaux d'amélioration — logement de plus de 2 ans"],
            ['taux' => 5.5,  'label' => '5,5 %', 'aide' => 'Rénovation énergétique — hors PAC air/air'],
            ['taux' => 0.0,  'label' => '0 %',   'aide' => 'Non soumis / autoliquidation'],
        ];
    }
}

if (!function_exists('tva_normalise_taux')) {
    /**
     * Ramène une saisie quelconque à un taux exploitable : numérique, borné à [0, 100],
     * arrondi à 2 décimales (5,5 % doit survivre ; 5,4999 % n'a aucun sens comptable).
     *
     * Une valeur invalide retombe sur le taux par défaut et JAMAIS sur 0 : sous-collecter
     * la TVA se paie au redressement, sur-collecter se corrige par avoir.
     */
    function tva_normalise_taux($raw, float $defaut = TVA_TAUX_DEFAUT): float {
        if (is_string($raw)) $raw = str_replace(',', '.', trim($raw));
        if ($raw === '' || $raw === null || !is_numeric($raw)) return $defaut;
        $t = round((float)$raw, 2);
        if ($t < 0.0 || $t > 100.0) return $defaut;
        return $t;
    }
}

if (!function_exists('tva_label_taux')) {
    /** Affichage d'un taux : « 20 % », « 5,5 % », « 0 % » — décimales inutiles supprimées. */
    function tva_label_taux($taux): string {
        $s = number_format((float)$taux, 2, ',', ' ');
        return rtrim(rtrim($s, '0'), ',') . ' %';
    }
}

if (!function_exists('tva_round2')) {
    /**
     * Arrondi monétaire, identique au round2() des générateurs PDF et au round2() du JS.
     * Le petit décalage compense la représentation binaire : 1.005 est stocké légèrement
     * en dessous de 1,005 et round() le descendrait à 1,00 au lieu de 1,01.
     */
    function tva_round2($n): float {
        $n = (float)$n;
        return round($n + ($n < 0 ? -1e-12 : 1e-12), 2);
    }
}

if (!function_exists('tva_totaux')) {
    /**
     * Totaux d'un document et ventilation de la TVA par taux.
     *
     * L'art. 242 nonies A du CGI impose de faire apparaître, pour chaque taux, la base
     * HT et la taxe correspondante : un document multi-taux ne peut pas se contenter
     * d'une ligne « Total TVA ».
     *
     * L'accumulation se fait LIGNE PAR LIGNE, jamais en appliquant le taux à une base
     * déjà agrégée. C'est ce qui garantit qu'un document s'additionne dans tous les sens :
     *   - la colonne TTC somme exactement au Total TTC ;
     *   - les lignes de ventilation somment exactement au Total TVA ;
     *   - Total HT + Total TVA = Total TTC.
     * Agréger d'abord puis multiplier introduit un écart d'un centime (constaté sur 13 %
     * des devis multi-lignes lors du fuzzing), et le devis ne retombait plus sur sa facture.
     *
     * @param array $lignes [['ht'=>float, 'taux'=>float, 'tva'=>float|null], ...]
     *                      'tva' est recalculée si absente ou nulle.
     * @return array ['ht','tva','ttc','lignes'=>[['taux','label','ht','tva'], ...]]
     */
    function tva_totaux(array $lignes): array {
        $parTaux = [];
        $ht = 0.0; $tva = 0.0;

        foreach ($lignes as $l) {
            $taux = tva_normalise_taux($l['taux'] ?? null);
            $lHt  = tva_round2($l['ht'] ?? 0);
            $lTva = (array_key_exists('tva', $l) && $l['tva'] !== null)
                  ? tva_round2($l['tva'])
                  : tva_round2($lHt * $taux / 100.0);

            $k = (string)$taux;
            if (!isset($parTaux[$k])) {
                $parTaux[$k] = ['taux' => $taux, 'label' => tva_label_taux($taux), 'ht' => 0.0, 'tva' => 0.0];
            }
            $parTaux[$k]['ht']  = tva_round2($parTaux[$k]['ht']  + $lHt);
            $parTaux[$k]['tva'] = tva_round2($parTaux[$k]['tva'] + $lTva);

            $ht  = tva_round2($ht  + $lHt);
            $tva = tva_round2($tva + $lTva);
        }

        // Un taux sans base ni taxe n'apporte aucune information : typiquement le groupe
        // créé par les seuls articles offerts. On ne l'imprime pas.
        $parTaux = array_values(array_filter(
            $parTaux,
            fn($b) => abs($b['ht']) > 0.0001 || abs($b['tva']) > 0.0001
        ));
        usort($parTaux, fn($a, $b) => $a['taux'] <=> $b['taux']);

        return ['ht' => $ht, 'tva' => $tva, 'ttc' => tva_round2($ht + $tva), 'lignes' => $parTaux];
    }
}
