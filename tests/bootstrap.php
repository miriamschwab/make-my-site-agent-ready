<?php
/**
 * Test bootstrap: the shared WordPress stubs, then the classes under test.
 *
 * Only the two agent-log classes are loaded. They are the ones whose logic is pure enough to test
 * without a database, and loading the whole plugin would drag in rewrite rules and admin screens
 * that need a real WordPress to mean anything.
 *
 * @package Make_My_Site_Agent_Ready
 */

require_once dirname( __DIR__, 2 ) . '/.audit-tools/tests-bootstrap/wp-stubs.php';

require_once dirname( __DIR__ ) . '/includes/class-mmsar-agent-log.php';
require_once dirname( __DIR__ ) . '/includes/class-mmsar-agent-log-verify.php';
