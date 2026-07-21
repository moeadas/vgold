<?php
/**
 * VGold ⟷ CRM table-name rewriting layer.
 *
 * In the unified VGold database, every CRM table is stored under a `crm_`
 * prefix (crm_leads, crm_users, crm_interactions, …) so that CRM data can live
 * in ONE database alongside the VGo workflow tables without name collisions
 * (notably `users` and `notifications`, which exist in BOTH systems).
 *
 * The legacy CRM codebase issues ~450 SQL statements against the *unprefixed*
 * names (`FROM leads`, `JOIN users`, `INSERT INTO interactions`, …). Rather
 * than hand-editing every query (fragile, easy to corrupt PHP identifiers that
 * merely *contain* a table name), we rewrite SQL centrally at the PDO boundary.
 *
 * A `CrmRewritingPDO` subclass overrides prepare()/query()/exec() and rewrites
 * only whole-word table tokens using a strict tokenizer that:
 *   - skips single-quoted, double-quoted and backtick-quoted string literals
 *   - only rewrites an identifier when it is NOT already prefixed and is a
 *     standalone word (no leading `crm_`, no surrounding identifier chars).
 *
 * This is active ONLY when the VGold bridge is loaded; standalone CRM keeps
 * using the raw PDO. Idempotent: a name already written as `crm_x` is left as is.
 */

if (defined('CRM_TABLE_REWRITE_LOADED')) return;
define('CRM_TABLE_REWRITE_LOADED', true);

/** Canonical list of CRM tables that get the `crm_` prefix in the unified DB. */
function crm_prefixed_tables(): array {
    static $t = [
        'leads', 'users', 'interactions', 'activity_log', 'notifications',
        'settings', 'proposals', 'documents', 'knowledge_hub_cards',
        'automation_rules', 'automation_logs',
        'email_campaigns', 'email_campaign_log', 'email_lists',
        'email_list_members', 'email_templates',
        'voip_calls', 'whatsapp_messages', 'whatsapp_templates',
        'webhook_endpoints', 'webhook_log',
    ];
    return $t;
}

/**
 * Rewrite unprefixed CRM table identifiers to their `crm_` form.
 * String-literal aware; only rewrites bare word tokens.
 */
function crm_rewrite_sql(string $sql): string {
    static $regex = null;
    if ($regex === null) {
        $alt = implode('|', array_map('preg_quote', crm_prefixed_tables()));
        // Match a table name only when:
        //   - not preceded by an identifier char, `.`, or the `crm_` prefix
        //   - not followed by an identifier char
        // (?<![\w.]) guards against col.users / mytable / crm_users
        $regex = '/(?<![\w.])(?<!crm_)(' . $alt . ')(?![\w])/i';
    }

    $out = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        // Pass string literals through untouched.
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $out .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                $out .= $c;
                if ($c === '\\' && $i + 1 < $len) { // escaped char inside literal
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $i++;
                if ($c === $quote) break;
            }
            continue;
        }
        // Accumulate a run of non-quote text, rewrite it, then continue.
        $start = $i;
        while ($i < $len && $sql[$i] !== "'" && $sql[$i] !== '"' && $sql[$i] !== '`') {
            $i++;
        }
        $chunk = substr($sql, $start, $i - $start);
        $out .= preg_replace($regex, 'crm_$1', $chunk);
    }
    return $out;
}

/**
 * PDO subclass that transparently rewrites CRM table names on every statement.
 */
class CrmRewritingPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(crm_rewrite_sql($query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs) {
        $query = crm_rewrite_sql($query);
        if ($fetchMode === null) return parent::query($query);
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function exec(string $statement): int|false {
        return parent::exec(crm_rewrite_sql($statement));
    }
}
