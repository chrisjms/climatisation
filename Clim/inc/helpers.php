<?php
// inc/helpers.php — petits utilitaires partagés.

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $name): bool {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$name]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('list_columns')) {
    function list_columns(PDO $pdo, string $table): array {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `$table`");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r) => $r['Field'], $rows ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ensure_unique_index')) {
    /**
     * Garantit la présence d'un index UNIQUE sur une colonne. Idempotent.
     * Si la création échoue (ex. doublons préexistants), on log mais on ne casse pas l'app.
     * Utilisé pour protéger les numérotations séquentielles devis/factures/BDC d'une race condition.
     */
    function ensure_unique_index(PDO $pdo, string $table, string $column, ?string $indexName = null): void {
        if ($indexName === null) $indexName = "uq_{$table}_{$column}";
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = :t
                    AND COLUMN_NAME  = :c
                    AND NON_UNIQUE   = 0
                  LIMIT 1"
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            if ($stmt->fetchColumn()) return;
            $pdo->exec("ALTER TABLE `$table` ADD UNIQUE INDEX `$indexName` (`$column`)");
        } catch (Throwable $e) {
            error_log("[ensure_unique_index] $table.$column : " . $e->getMessage());
        }
    }
}

if (!function_exists('is_duplicate_key_error')) {
    /** Détecte une violation de contrainte UNIQUE/PK (SQLSTATE 23000). */
    function is_duplicate_key_error(Throwable $e): bool {
        if ($e instanceof PDOException) {
            $code = (string)($e->errorInfo[0] ?? $e->getCode());
            return $code === '23000';
        }
        return strpos($e->getMessage(), '23000') !== false;
    }
}
