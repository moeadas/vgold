<?php
if (defined('CRM_TABLE_REWRITE_LOADED')) return;
define('CRM_TABLE_REWRITE_LOADED', true);

function crm_prefixed_tables(): array {
    return [
        'leads','users','interactions','activity_log','notifications','settings',
        'proposals','documents','knowledge_hub_cards','automation_rules','automation_logs',
        'email_campaigns','email_campaign_log','email_lists','email_list_members','email_templates',
        'voip_calls','whatsapp_messages','whatsapp_templates','webhook_endpoints','webhook_log',
    ];
}

function crm_rewrite_sql(string $sql): string {
    static $regex = null, $identifiers = null;
    if ($regex === null) {
        $alt = implode('|', array_map('preg_quote', crm_prefixed_tables()));
        $regex = '/(?<![\w.])(?<!crm_)(' . $alt . ')(?![\w])/i';
        $identifiers = array_fill_keys(array_map('strtolower', crm_prefixed_tables()), true);
    }
    $out = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length;) {
        $char = $sql[$i];
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $out .= $char;
            $i++;
            while ($i < $length) {
                $current = $sql[$i];
                $out .= $current;
                if ($current === '\\' && $i + 1 < $length) {
                    $out .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $i++;
                if ($current === $quote) break;
            }
            continue;
        }
        if ($char === '`') {
            $i++;
            $inner = '';
            while ($i < $length && $sql[$i] !== '`') $inner .= $sql[$i++];
            $i++;
            $lower = strtolower($inner);
            if (strncmp($lower, 'crm_', 4) !== 0 && isset($identifiers[$lower])) $inner = 'crm_' . $inner;
            $out .= '`' . $inner . '`';
            continue;
        }
        $start = $i;
        while ($i < $length && $sql[$i] !== "'" && $sql[$i] !== '"' && $sql[$i] !== '`') $i++;
        $out .= preg_replace($regex, 'crm_$1', substr($sql, $start, $i - $start));
    }
    return $out;
}

class CrmRewritingPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(crm_rewrite_sql($query), $options);
    }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs) {
        $query = crm_rewrite_sql($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function exec(string $statement): int|false {
        return parent::exec(crm_rewrite_sql($statement));
    }
}
