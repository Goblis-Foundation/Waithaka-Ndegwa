<?php
declare(strict_types=1);

/** Editorial collections exclude every Publishing Desk record, in any state. */
function editorial_post_sql(string $alias = 'p'): string {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid post alias.');
    }
    // The key alone is sufficient; encrypted record contents are not read by SQL.
    return "NOT EXISTS (SELECT 1 FROM settings editorial_binding WHERE "
        . "editorial_binding.setting_key=CONCAT('communication.desk.post.'," . $alias . ".id))";
}

/** Keep the general publication predicate available for private publishing controls. */
function editorial_public_where(): string {
    return published_where() . ' AND ' . editorial_post_sql();
}
