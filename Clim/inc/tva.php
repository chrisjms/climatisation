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

if (!function_exists('tva_ventilation')) {
    /**
     * Ventilation de la TVA par taux, à partir d'une base HT regroupée par taux.
     *
     * L'art. 242 nonies A du CGI impose de faire apparaître, pour chaque taux, la base
     * HT et le montant de taxe correspondant : une facture multi-taux ne peut pas se
     * contenter d'une ligne « Total TVA ».
     *
     * @param array $htByRate  ['20' => 1200.00, '10' => 300.00] — clés = taux
     * @return array           [['taux'=>, 'label'=>, 'ht'=>, 'tva'=>], ...] triée par taux croissant
     */
    function tva_ventilation(array $htByRate): array {
        $out = [];
        foreach ($htByRate as $rateStr => $ht) {
            $taux = (float)$rateStr;
            // Un taux sans base HT n'apporte aucune information : typiquement le bucket 0 %
            // créé par les seuls articles offerts. On ne l'imprime pas.
            if (round((float)$ht, 2) === 0.0) continue;
            $out[] = [
                'taux'  => $taux,
                'label' => tva_label_taux($taux),
                'ht'    => round((float)$ht, 2),
                'tva'   => round((float)$ht * $taux / 100.0, 2),
            ];
        }
        usort($out, fn($a, $b) => $a['taux'] <=> $b['taux']);
        return $out;
    }
}
